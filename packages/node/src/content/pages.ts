import { type Kysely, type SelectQueryBuilder, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { encodeJson, flag, sqlDate } from "../support/values";
import { isLive, type Page, pageFromRow, TRANSLATABLE } from "./page";
import { assertHierarchy, assertTypePair, PageRuleError } from "./pageRules";
import { PageTypes } from "./pageTypes";
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

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		frontendLocales: () => Promise<string[]>,
		defaultLocale: () => Promise<string>,
		/** The tree rules every write checks; the built-ins when omitted. */
		private readonly types: PageTypes = new PageTypes(),
		/** Content snapshots (`revisions.keep`, default 25). */
		readonly revisions: PageRevisions = new PageRevisions(db, 25),
	) {
		this.slugs = new PageSlugs(db, frontendLocales, defaultLocale);
	}

	query(): PagesQuery {
		return this.db.selectFrom("tbtop_pages").where("tbtop_pages.deleted_at", "is", null);
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

	/** AncestorChain::forPage: root first, the page itself excluded, cycles cut. */
	async ancestors(page: Page, maxSteps = 64): Promise<Page[]> {
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
		const [before, page] = await this.write(id, input, options);
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
		const [, page] = await this.write(id, PageRevisions.restoreInput(revision), options);
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
				...this.columns(input),
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
		return [current, page];
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
		await this.db.updateTable("tbtop_pages").set({ deleted_at: sqlDate(new Date()) }).where("id", "=", id).execute();
		await this.slugs.remove(id);
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
