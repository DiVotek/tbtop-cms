import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { type Dict, decodeJson, isDict, parseDate, sqlDate } from "../support/values";
import { type Page, TRANSLATABLE } from "./page";

export type RevisionKind = "create" | "save" | "restore";

/** A snapshot of a page's editorial fields; `status` is shown, never restored. */
export type PageRevision = { id: number; pageId: number; userId: string | number | null; kind: RevisionKind; payload: Dict; createdAt: Date | null };

/** What a revision holds (PageRevisionWriter::EDITORIAL_FIELDS); status, type, tree and slugs stay out. */
const EDITORIAL = [...TRANSLATABLE, "image", "content", "fields", "seo", "isIndexable"] as const;

const userKey = (raw: unknown): string | number | null =>
	raw === null || raw === undefined ? null : typeof raw === "string" && /^\d+$/.test(raw) ? Number(raw) : (raw as string | number);

/**
 * Content snapshots per page (PageRevisionWriter.php): one on create, one per
 * save that changes an editorial field, one per restore; the newest `keep`
 * rows stay. `keep` 0 turns the feature off.
 */
export class PageRevisions {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		readonly keep: number,
	) {}

	get enabled(): boolean {
		return this.keep > 0;
	}

	/** Did a write change anything a revision snapshots? */
	static changed(before: Page, after: Page): boolean {
		return EDITORIAL.some((field) => JSON.stringify(before[field] ?? null) !== JSON.stringify(after[field] ?? null));
	}

	async snapshot(page: Page, kind: RevisionKind, userId: string | number | null = null): Promise<void> {
		if (!this.enabled) {
			return;
		}
		const payload: Dict = { status: page.status };
		for (const field of EDITORIAL) {
			payload[field] = page[field] ?? null;
		}
		await this.db
			.insertInto("tbtop_page_revisions")
			.values({ page_id: page.id, user_id: userId, kind, payload: JSON.stringify(payload), created_at: sqlDate(new Date()) })
			.execute();
		await this.prune(page.id);
	}

	/** Newest first, at most `keep`. */
	async list(pageId: number): Promise<PageRevision[]> {
		if (!this.enabled) {
			return [];
		}
		const rows = await this.db
			.selectFrom("tbtop_page_revisions")
			.selectAll()
			.where("page_id", "=", pageId)
			.orderBy("created_at", "desc")
			.orderBy("id", "desc")
			.limit(this.keep)
			.execute();
		return rows.map((row) => {
			const payload = decodeJson(row.payload);
			return {
				id: Number(row.id),
				pageId: Number(row.page_id),
				userId: userKey(row.user_id),
				kind: row.kind as RevisionKind,
				payload: isDict(payload) ? payload : {},
				createdAt: parseDate(row.created_at),
			};
		});
	}

	async find(pageId: number, revisionId: number): Promise<PageRevision | null> {
		return (await this.list(pageId)).find((revision) => revision.id === revisionId) ?? null;
	}

	/**
	 * The page input that puts a revision's fields back. A key an older payload
	 * lacks is left alone, so the rest still restores.
	 */
	static restoreInput(revision: PageRevision): Partial<Pick<Page, (typeof EDITORIAL)[number]>> {
		const out: Dict = {};
		for (const field of EDITORIAL) {
			if (Object.hasOwn(revision.payload, field)) {
				out[field] = field === "isIndexable" ? Boolean(revision.payload[field]) : revision.payload[field];
			}
		}
		return out as Partial<Pick<Page, (typeof EDITORIAL)[number]>>;
	}

	private async prune(pageId: number): Promise<void> {
		const keepIds = (
			await this.db
				.selectFrom("tbtop_page_revisions")
				.select("id")
				.where("page_id", "=", pageId)
				.orderBy("created_at", "desc")
				.orderBy("id", "desc")
				.limit(this.keep)
				.execute()
		).map((row) => Number(row.id));
		await this.db.deleteFrom("tbtop_page_revisions").where("page_id", "=", pageId).where("id", "not in", keepIds).execute();
	}
}
