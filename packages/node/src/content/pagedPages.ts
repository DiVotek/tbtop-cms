import { type SelectQueryBuilder, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { type Page, pageFromRow } from "./page";

/** Where a paged archive stands: page `page` of `pages`, `total` pages in all. */
export type Pagination = { page: number; pages: number; total: number; perPage: number };

/** One page of an archive's links and where it stands. */
export type PagedRefs<R> = { items: R[]; pagination: Pagination };

export const PAGE_QUERY_KEY = "page";

/**
 * The request's paged archive (CurrentPage): the `?page=` it asks for, and
 * the rendered page's own listing (its tag or category archive, by that
 * number), so the page gets a `?page=N` canonical and a page past the end is
 * a 404. A listing of another page (a sidebar) or with a given page number
 * is not recorded. `?page=` is one number for the whole URL, so a page lists
 * one paged archive of its own.
 */
export class Paging {
	requested = 1;
	result: Pagination | null = null;
	/** The page being rendered, whose own archive the result is. */
	private renderedId: number | null = null;

	/** `?page=` of the URL when it is a positive integer, else 1 (PagedPages::requested). */
	read(url: URL, renderedId: number): void {
		const raw = url.searchParams.get(PAGE_QUERY_KEY);
		this.requested = raw !== null && /^[1-9][0-9]{0,8}$/.test(raw) ? Number(raw) : 1;
		this.renderedId = renderedId;
	}

	record(ownerId: number, pagination: Pagination): void {
		if (ownerId === this.renderedId) {
			this.result = pagination;
		}
	}
}

type PagesQuery = SelectQueryBuilder<CmsDatabase, "tbtop_pages", object>;

/**
 * PagedPages::take: one page of live pages for an archive, newest first by
 * published_at (a page without one last), then id. The page number is the
 * request's unless given; `owner` is the page whose archive this is (the
 * tag, the category), recorded on `paging` when it is the rendered page.
 */
export async function takePage(
	paging: Paging,
	query: PagesQuery,
	options: { owner: number; perPage: number; page?: number },
): Promise<{ pages: Page[]; pagination: Pagination }> {
	const { perPage } = options;
	if (!Number.isInteger(perPage) || perPage < 1) {
		throw new Error("perPage needs at least 1.");
	}
	const page = Math.max(1, options.page ?? paging.requested);
	const counted = await query.select(sql<number>`count(*)`.as("count")).executeTakeFirst();
	const total = Number(counted?.count ?? 0);
	const pages = Math.max(1, Math.ceil(total / perPage));
	const rows = await query
		.selectAll("tbtop_pages")
		// NULL last on every dialect: a page published before the first-publish stamp has none.
		.orderBy(sql`tbtop_pages.published_at is null`)
		.orderBy("tbtop_pages.published_at", "desc")
		.orderBy("tbtop_pages.id", "desc")
		.limit(perPage)
		.offset((page - 1) * perPage)
		.execute();
	const pagination = { page, pages, total, perPage };
	if (options.page === undefined) {
		paging.record(options.owner, pagination);
	}
	return { pages: rows.map(pageFromRow), pagination };
}
