import { type Kysely, type SelectQueryBuilder, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { encodeJson, flag, parseDate, sqlDate } from "../support/values";
import { AUDIT_SUBJECTS, AuditLog } from "./audit";
import { isLive, type Page, type PageStatus, pageFromRow, TRANSLATABLE } from "./page";
import { assertHierarchy, assertTypePair, PageRuleError } from "./pageRules";
import { PageTypes } from "./pageTypes";
import { writePageRefs } from "./pageRefIndex";
import { PageRevisions } from "./revisions";
import { PageSlugs } from "./slugs";

type PagesQuery = SelectQueryBuilder<CmsDatabase, "tbtop_pages", object>;

/** Page::scopeLive: published, published_at passed (or null), unpublish_at ahead (or null). */
export function whereLive<Q extends PagesQuery>(qb: Q, now = new Date()): Q {
	const at = sqlDate(now);
	return qb
		.where("tbtop_pages.status", "=", "published")
		.where((eb) => eb.or([eb("tbtop_pages.published_at", "is", null), eb("tbtop_pages.published_at", "<=", at)]))
		.where((eb) => eb.or([eb("tbtop_pages.unpublish_at", "is", null), eb("tbtop_pages.unpublish_at", ">", at)])) as Q;
}

/** What a page write takes: translatable fields as locale maps, the rest as on Page. */
export type PageInput = Partial<
	Omit<Page, "id" | "createdAt" | "updatedAt" | "lockVersion" | "createdBy" | "updatedBy">
> & { type?: string };

/** Pages and their slugs (Page.php + PageObserver's slug sync). */
export class Pages {
	readonly slugs: PageSlugs;
	/** home() and ancestors() by page id, once readOnce() is on. */
	private memo: { home: Promise<Page | null> | null; ancestors: Map<number, Promise<Page[]>> } | null = null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly frontendLocales: () => Promise<string[]>,
		defaultLocale: () => Promise<string>,
		/** The tree rules every write checks; the built-ins when omitted. */
		private readonly types: PageTypes = new PageTypes(),
		/** Content snapshots (`revisions.keep`, default 25). */
		readonly revisions: PageRevisions = new PageRevisions(db, 25),
		/** The admin's audit trail; records nothing outside a signed-in admin request. */
		private readonly audit: AuditLog = new AuditLog(db),
	) {
		this.slugs = new PageSlugs(db, frontendLocales, defaultLocale);
	}

	/**
	 * A frontend render: the home page, each page's ancestors and the slugs
	 * are read once and answered from memory after. Only for an instance that
	 * writes no pages.
	 */
	readOnce(): void {
		this.memo ??= { home: null, ancestors: new Map() };
		this.slugs.readOnce();
	}

	query(): PagesQuery {
		return this.db.selectFrom("tbtop_pages").where("tbtop_pages.deleted_at", "is", null);
	}

	/** The page at a path in a locale (the slug lookup and the page in one query); live only when asked. */
	async findAt(path: string, locale: string, options: { live?: boolean } = {}): Promise<Page | null> {
		let qb = this.query()
			.innerJoin("tbtop_page_slugs", "tbtop_page_slugs.page_id", "tbtop_pages.id")
			.selectAll("tbtop_pages")
			.where("tbtop_page_slugs.locale", "=", locale)
			.where("tbtop_page_slugs.path", "=", path);
		if (options.live === true) {
			qb = whereLive(qb);
		}
		const row = await qb.executeTakeFirst();
		return row === undefined ? null : pageFromRow(row);
	}

	async find(id: number, options: { live?: boolean } = {}): Promise<Page | null> {
		let qb = this.query().selectAll("tbtop_pages").where("tbtop_pages.id", "=", id);
		if (options.live === true) {
			qb = whereLive(qb);
		}
		const row = await qb.executeTakeFirst();
		return row === undefined ? null : pageFromRow(row);
	}

	async findMany(ids: number[]): Promise<Page[]> {
		if (ids.length === 0) {
			return [];
		}
		return (await this.query().selectAll("tbtop_pages").where("id", "in", ids).execute()).map(pageFromRow);
	}

	/** HomePage::find(): the root system page that is not an error page. */
	async home(): Promise<Page | null> {
		if (this.memo !== null) {
			this.memo.home ??= this.loadHome();
			return this.memo.home;
		}
		return this.loadHome();
	}

	private async loadHome(): Promise<Page | null> {
		const row = await this.query()
			.selectAll("tbtop_pages")
			.where("is_system", "=", 1)
			.where("parent_id", "is", null)
			.where("error_class", "is", null)
			.executeTakeFirst();
		return row === undefined ? null : pageFromRow(row);
	}

	async children(id: number, options: { live?: boolean; indexable?: boolean; limit?: number } = {}): Promise<Page[]> {
		let qb = this.query().selectAll("tbtop_pages").where("parent_id", "=", id).orderBy("sort_order").orderBy("id");
		if (options.live === true) {
			qb = whereLive(qb);
		}
		if (options.indexable === true) {
			qb = qb.where("is_indexable", "=", 1);
		}
		if (options.limit !== undefined) {
			qb = qb.limit(options.limit);
		}
		return (await qb.execute()).map(pageFromRow);
	}

	/** NextScheduleTransition::compute: the epoch second of the nearest future published_at/unpublish_at of a published page, null when none. */
	async nextTransition(now = new Date()): Promise<number | null> {
		const at = sqlDate(now);
		const row = await this.query()
			.where("status", "=", "published")
			.select((eb) => [
				eb.fn.min(eb.case().when("published_at", ">", at).then(eb.ref("published_at")).end()).as("publish"),
				eb.fn.min(eb.case().when("unpublish_at", ">", at).then(eb.ref("unpublish_at")).end()).as("unpublish"),
			])
			.executeTakeFirst();
		const times = [row?.publish, row?.unpublish].map(parseDate).filter((date): date is Date => date !== null);
		return times.length === 0 ? null : Math.floor(Math.min(...times.map((date) => date.getTime())) / 1000);
	}

	/** AncestorChain::forPage: root first, the page itself excluded, cycles cut. */
	async ancestors(page: Page, maxSteps = 64): Promise<Page[]> {
		if (this.memo === null || maxSteps !== 64) {
			return this.loadAncestors(page, maxSteps);
		}
		const known = this.memo.ancestors.get(page.id) ?? this.loadAncestors(page, maxSteps);
		this.memo.ancestors.set(page.id, known);
		return [...(await known)];
	}

	private async loadAncestors(page: Page, maxSteps: number): Promise<Page[]> {
		const chain: Page[] = [];
		const seen = new Set<number>([page.id]);
		let parentId = page.parentId;
		while (parentId !== null && chain.length < maxSteps && !seen.has(parentId)) {
			const parent = await this.find(parentId);
			if (parent === null) {
				break;
			}
			seen.add(parent.id);
			chain.unshift(parent);
			parentId = parent.parentId;
		}
		return chain;
	}

	async liveAncestors(page: Page): Promise<Page[]> {
		const now = new Date();
		return (await this.ancestors(page)).filter((ancestor) => isLive(ancestor, now));
	}

	/** Creates a page; the type's tree rules (PageHierarchy) throw PageRuleError. */
	async create(input: PageInput & { type: string }, options: { slugs?: Record<string, string>; by?: string | number | null } = {}): Promise<Page> {
		return this.audit.track(AUDIT_SUBJECTS.page, null, () => this.insert(input, options), { idOf: (page) => page.id });
	}

	private async insert(given: PageInput & { type: string }, options: { slugs?: Record<string, string>; by?: string | number | null }): Promise<Page> {
		const input = { ...given, ...firstPublishStamp(given, null) };
		assertTypePair(this.types, input.type, input.subtype ?? null);
		await assertHierarchy(this.db, this.types, { id: null, type: input.type, subtype: input.subtype ?? null, parentId: input.parentId ?? null });
		const now = sqlDate(new Date());
		const id = await insertId(
			this.db,
			this.db.insertInto("tbtop_pages")
			.values({
				...this.columns(input),
				type: input.type,
				status: input.status ?? "draft",
				is_indexable: flag(input.isIndexable ?? true),
				is_system: flag(input.isSystem ?? false),
				created_by: options.by ?? null,
				updated_by: options.by ?? null,
				created_at: now,
				updated_at: now,
			}),
		);
		const page = await this.mustFind(id);
		await this.slugs.sync(page, options.slugs ?? {});
		await writePageRefs(this.db, this.types, page);
		await this.revisions.snapshot(page, "create", options.by ?? null);
		return page;
	}

	/**
	 * Updates a page. With `lockVersion` the write only lands when the row still
	 * has it (optimistic lock); a stale version throws PageConflictError. A type
	 * or parent change re-checks the tree rules (PageRuleError).
	 */
	async update(
		id: number,
		input: PageInput,
		options: { slugs?: Record<string, string>; lockVersion?: number; by?: string | number | null } = {},
	): Promise<Page> {
		const [before, page] = await this.audit.track(AUDIT_SUBJECTS.page, id, () => this.write(id, input, options));
		if (PageRevisions.changed(before, page)) {
			await this.revisions.snapshot(page, "save", options.by ?? null);
		}
		return page;
	}

	/**
	 * Puts a revision's editorial fields back (PageRevisionWriter::restore);
	 * status, type, tree and slugs stay. Recorded as a `restore` revision even
	 * when nothing changed. An unknown revision throws PageRuleError.
	 */
	async restore(id: number, revisionId: number, options: { by?: string | number | null } = {}): Promise<Page> {
		const revision = await this.revisions.find(id, revisionId);
		if (revision === null) {
			throw new PageRuleError("The revision does not belong to this page.");
		}
		const [, page] = await this.audit.track(AUDIT_SUBJECTS.page, id, () => this.write(id, PageRevisions.restoreInput(revision), options), {
			event: "revision_restored",
			context: { revision_id: revisionId },
		});
		await this.revisions.snapshot(page, "restore", options.by ?? null);
		return page;
	}

	private async write(
		id: number,
		input: PageInput,
		options: { slugs?: Record<string, string>; lockVersion?: number; by?: string | number | null },
	): Promise<[Page, Page]> {
		const current = await this.find(id);
		if (current === null) {
			throw new PageConflictError(id);
		}
		await this.guardUpdate(current, input);
		let qb = this.db
			.updateTable("tbtop_pages")
			.set({
				...this.columns({ ...input, ...firstPublishStamp(input, current) }),
				...(input.type !== undefined ? { type: input.type } : {}),
				...(input.status !== undefined ? { status: input.status } : {}),
				...(input.isIndexable !== undefined ? { is_indexable: flag(input.isIndexable) } : {}),
				...(input.isSystem !== undefined ? { is_system: flag(input.isSystem) } : {}),
				...(options.by !== undefined ? { updated_by: options.by } : {}),
				lock_version: sql<number>`lock_version + 1`,
				updated_at: sqlDate(new Date()),
			})
			.where("id", "=", id);
		if (options.lockVersion !== undefined) {
			qb = qb.where("lock_version", "=", options.lockVersion);
		}
		const result = await qb.executeTakeFirst();
		if (Number(result.numUpdatedRows) === 0) {
			throw new PageConflictError(id);
		}
		const page = await this.mustFind(id);
		await this.slugs.sync(page, options.slugs ?? {});
		await writePageRefs(this.db, this.types, page);
		return [current, page];
	}

	/** The page (not trashed) of a source-backed type for a source id; null when there is none. */
	async findBySource(type: string, sourceId: string): Promise<Page | null> {
		const row = await this.query().selectAll("tbtop_pages").where("type", "=", type).where("source_id", "=", sourceId).orderBy("id").executeTakeFirst();
		return row === undefined ? null : pageFromRow(row);
	}

	/** Every page (not trashed) of a source-backed type, for a reconcile against the sources. */
	async allBySource(type: string): Promise<Page[]> {
		return (await this.query().selectAll("tbtop_pages").where("type", "=", type).where("source_id", "is not", null).orderBy("id").execute()).map(pageFromRow);
	}

	/**
	 * The page of `(type, sourceId)`, created once when missing: its name, its
	 * slug (one for every frontend locale, or one per locale; a taken path gets
	 * `-2`…; else from the name) and its parent are written only then, never
	 * again. Idempotent: two racing calls leave one page. Only for a
	 * source-backed type (PageRuleError otherwise).
	 */
	async ensureSource(
		type: string,
		sourceId: string,
		input: { name: Record<string, unknown>; slug?: string | Record<string, string>; parentId?: number | null; status?: PageStatus },
		options: { by?: string | number | null } = {},
	): Promise<{ page: Page; created: boolean }> {
		this.assertSourceBacked(type);
		const existing = await this.findBySource(type, sourceId);
		if (existing !== null) {
			return { page: existing, created: false };
		}
		const slugs = typeof input.slug === "string" ? Object.fromEntries((await this.frontendLocales()).map((locale) => [locale, input.slug as string])) : (input.slug ?? {});
		const page = await this.create(
			{ type, sourceId, name: input.name, parentId: input.parentId ?? null, status: input.status ?? "draft", content: null },
			{ slugs, by: options.by ?? null },
		);
		// A racing call may have created one meanwhile: the oldest stays, this one goes (its audit entry, if any, stays).
		const first = await this.findBySource(type, sourceId);
		if (first !== null && first.id !== page.id) {
			await this.discard(page.id);
			return { page: first, created: false };
		}
		return { page, created: true };
	}

	/** Publishes or unpublishes the page of `(type, sourceId)`; null when there is none. */
	async setSourceStatus(type: string, sourceId: string, status: PageStatus, options: { by?: string | number | null } = {}): Promise<Page | null> {
		const page = await this.findBySource(type, sourceId);
		if (page === null) {
			return null;
		}
		return page.status === status ? page : this.update(page.id, { status }, { by: options.by ?? null });
	}

	/**
	 * Moves the page of `(type, sourceId)` to the trash, as the editor's
	 * delete does; false when there is none. A page with child pages refuses
	 * (PageRuleError): delete or move them first.
	 */
	async deleteSource(type: string, sourceId: string): Promise<boolean> {
		const page = await this.findBySource(type, sourceId);
		if (page === null) {
			return false;
		}
		await this.delete(page.id);
		return true;
	}

	private assertSourceBacked(type: string): void {
		if (!this.types.has(type) || !this.types.get(type).sourceBacked) {
			throw new PageRuleError(`Page type [${type}] is not source-backed.`);
		}
	}

	/** A page a racing ensureSource created twice: gone with its slugs, revisions and link index. */
	private async discard(id: number): Promise<void> {
		await this.slugs.remove(id);
		await this.db.deleteFrom("tbtop_page_revisions").where("page_id", "=", id).execute();
		await this.db.deleteFrom("tbtop_page_refs").where("page_id", "=", id).execute();
		await this.db.deleteFrom("tbtop_pages").where("id", "=", id).execute();
	}

	/** Soft delete; the slugs go, so the path frees up. A system page or one with live children throws PageRuleError. */
	async delete(id: number): Promise<void> {
		const page = await this.find(id);
		if (page?.isSystem === true) {
			throw new PageRuleError("System pages cannot be deleted.");
		}
		if ((await this.query().select("id").where("parent_id", "=", id).executeTakeFirst()) !== undefined) {
			throw new PageRuleError("This page has child pages — move or delete them first.");
		}
		await this.audit.track(AUDIT_SUBJECTS.page, id, async () => {
			await this.db.updateTable("tbtop_pages").set({ deleted_at: sqlDate(new Date()) }).where("id", "=", id).execute();
			await this.slugs.remove(id);
		});
	}

	/**
	 * Takes a page out of the trash (Page::restore + PageObserver::restoring):
	 * the tree rules run again, a trashed parent refuses it (PageRuleError),
	 * and the slugs come back from the name, suffixed if the path was taken.
	 */
	async untrash(id: number): Promise<Page> {
		const row = await this.db.selectFrom("tbtop_pages").selectAll().where("id", "=", id).where("deleted_at", "is not", null).executeTakeFirst();
		if (row === undefined) {
			throw new PageRuleError("The page is not in the trash.");
		}
		const trashed = pageFromRow(row);
		// One page per source: code made a new one after this went to the trash.
		if (trashed.sourceId !== null && this.types.isSourceBacked(trashed) && (await this.findBySource(trashed.type, trashed.sourceId)) !== null) {
			throw new PageRuleError("Another page already shows this record.");
		}
		if (trashed.parentId !== null) {
			const parent = await this.db.selectFrom("tbtop_pages").select("deleted_at").where("id", "=", trashed.parentId).executeTakeFirst();
			if (parent !== undefined && parent.deleted_at !== null) {
				throw new PageRuleError("Restore the parent page first.");
			}
		}
		await assertHierarchy(this.db, this.types, { id, type: trashed.type, subtype: trashed.subtype, parentId: trashed.parentId }, { type: true, parent: true });
		await this.audit.track(AUDIT_SUBJECTS.page, id, async () => {
			await this.db
				.updateTable("tbtop_pages")
				.set((eb) => ({ deleted_at: null, lock_version: eb("lock_version", "+", 1), updated_at: sqlDate(new Date()) }))
				.where("id", "=", id)
				.execute();
		});
		const page = await this.mustFind(id);
		await this.slugs.sync(page);
		return page;
	}

	/**
	 * Deletes a trashed page for good, with its slugs, revisions and link
	 * index; its form submissions keep their rows without the page. A page
	 * still parenting another one (trashed included) throws PageRuleError.
	 */
	async forceDelete(id: number): Promise<void> {
		const row = await this.db.selectFrom("tbtop_pages").select("id").where("id", "=", id).where("deleted_at", "is not", null).executeTakeFirst();
		if (row === undefined) {
			throw new PageRuleError("Only a page in the trash can be deleted permanently.");
		}
		if ((await this.db.selectFrom("tbtop_pages").select("id").where("parent_id", "=", id).executeTakeFirst()) !== undefined) {
			throw new PageRuleError("This page has child pages — move or delete them first.");
		}
		await this.audit.track(
			AUDIT_SUBJECTS.page,
			id,
			async () => {
				// One by one rather than by foreign keys: SQLite enforces them only with PRAGMA foreign_keys.
				await this.slugs.remove(id);
				await this.db.deleteFrom("tbtop_page_revisions").where("page_id", "=", id).execute();
				await this.db.deleteFrom("tbtop_page_refs").where("page_id", "=", id).execute();
				await this.db.updateTable("tbtop_form_submissions").set({ page_id: null }).where("page_id", "=", id).execute();
				await this.db.deleteFrom("tbtop_pages").where("id", "=", id).execute();
			},
			{ event: "force_deleted" },
		);
	}

	/**
	 * PageDuplicator: a draft copy next to the original (same parent, last
	 * among its siblings), " (copy)" after each name. Not copied: children,
	 * revisions (the copy starts its own), slugs (derived from the new name),
	 * the system flag and the error class.
	 */
	async duplicate(id: number, options: { by?: string | number | null } = {}): Promise<Page> {
		const source = await this.find(id);
		if (source === null) {
			throw new PageRuleError("The page is already gone.");
		}
		if (this.types.isSourceBacked(source)) {
			throw new PageRuleError("This page mirrors a record of another system: it cannot be duplicated.");
		}
		const last = await this.db
			.selectFrom("tbtop_pages")
			.select((eb) => eb.fn.max("sort_order").as("max"))
			.where((eb) => (source.parentId === null ? eb("parent_id", "is", null) : eb("parent_id", "=", source.parentId)))
			.executeTakeFirst();
		const name = Object.fromEntries(Object.entries(source.name).map(([locale, value]) => [locale, typeof value === "string" && value !== "" ? `${value} (copy)` : value]));
		const { id: _id, createdAt: _created, updatedAt: _updated, lockVersion: _lock, createdBy: _createdBy, updatedBy: _updatedBy, ...copy } = source;
		return this.audit.track(
			AUDIT_SUBJECTS.page,
			null,
			() =>
				this.insert(
					{ ...copy, name, status: "draft", publishedAt: null, unpublishAt: null, isSystem: false, errorClass: null, sortOrder: last?.max === null || last?.max === undefined ? 0 : Number(last.max) + 1 },
					options,
				),
			{ idOf: (page) => page.id, event: "duplicated", context: { source_page_id: id } },
		);
	}

	/** PageObserver::updating's checks, before anything is written. */
	private async guardUpdate(current: Page, input: PageInput): Promise<void> {
		const type = input.type ?? current.type;
		const parentId = input.parentId !== undefined ? input.parentId : current.parentId;
		const subtype = input.subtype !== undefined ? input.subtype : current.subtype;
		const changed = { type: type !== current.type, parent: parentId !== current.parentId };
		if (changed.type || subtype !== current.subtype) {
			assertTypePair(this.types, type, subtype);
		}
		if ((input.isSystem ?? current.isSystem) && input.status !== undefined && input.status !== current.status) {
			throw new PageRuleError("The status of a system page cannot be changed.");
		}
		if (input.errorClass !== undefined && input.errorClass !== current.errorClass) {
			throw new PageRuleError("An error page's class (4xx/5xx) cannot be changed after creation.");
		}
		if (changed.type || changed.parent) {
			await assertHierarchy(this.db, this.types, { id: current.id, type, subtype, parentId }, changed);
		}
	}

	private async mustFind(id: number): Promise<Page> {
		const page = await this.find(id);
		if (page === null) {
			throw new Error(`Page #${id} not found.`);
		}
		return page;
	}

	private columns(input: PageInput) {
		const out: Record<string, unknown> = {};
		for (const field of TRANSLATABLE) {
			if (input[field] !== undefined) {
				out[field] = encodeJson(input[field]);
			}
		}
		const map: [keyof PageInput, string, (value: never) => unknown][] = [
			["subtype", "subtype", (v) => v],
			["sourceId", "source_id", (v) => v],
			["parentId", "parent_id", (v) => v],
			["publishedAt", "published_at", (v: Date | null) => (v === null ? null : sqlDate(v))],
			["unpublishAt", "unpublish_at", (v: Date | null) => (v === null ? null : sqlDate(v))],
			["content", "content", encodeJson],
			["fields", "fields", encodeJson],
			["starterBlocks", "starter_blocks", encodeJson],
			["image", "image", (v) => v],
			["seo", "seo", encodeJson],
			["errorClass", "error_class", (v) => v],
			["sortOrder", "sort_order", (v) => v],
		];
		for (const [key, column, encode] of map) {
			if (input[key] !== undefined) {
				out[column] = encode(input[key] as never);
			}
		}
		return out;
	}
}

export class PageConflictError extends Error {
	constructor(readonly pageId: number) {
		super(`Page #${pageId} was changed by someone else.`);
	}
}

/**
 * PageObserver::stampFirstPublish: the first publish with no date set stamps
 * published_at with now, so the page has a datePublished. A date the editor
 * set (a future one schedules the page) is left alone, and a republish keeps
 * the first stamp: unpublishing does not clear published_at.
 */
function firstPublishStamp(input: PageInput, current: Page | null): Pick<PageInput, "publishedAt"> {
	const publishes = input.status === "published" && current?.status !== "published";
	const publishedAt = input.publishedAt !== undefined ? input.publishedAt : (current?.publishedAt ?? null);
	return publishes && publishedAt === null ? { publishedAt: new Date() } : {};
}
