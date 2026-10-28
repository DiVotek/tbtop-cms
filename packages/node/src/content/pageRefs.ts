import { isLive, type Page, pageFromRow } from "./page";
import { type PagedRefs, takePage } from "./pagedPages";
import { whereLive } from "./pages";
import type { Site } from "../site";

/** One page as the site sees it (PageRefList): its id, name and URL in one locale. */
export type PageRef = { id: string; name: string; url: string };

/** PageIds::one: a positive integer id, or its decimal string of at most 18 digits. */
export function pageId(value: unknown): number | null {
	if (typeof value === "number") {
		return Number.isSafeInteger(value) && value > 0 ? value : null;
	}
	if (typeof value !== "string" || !/^\d{1,18}$/.test(value)) {
		return null;
	}
	const id = Number(value);
	return Number.isSafeInteger(id) && id > 0 ? id : null;
}

/** PageIds::list: the ids of a stored pages value, in stored order, first occurrence kept. */
export function pageIds(value: unknown): number[] {
	const out: number[] = [];
	for (const item of Array.isArray(value) ? value : []) {
		const id = pageId(item);
		if (id !== null && !out.includes(id)) {
			out.push(id);
		}
	}
	return out;
}

/** PageRefList::nameOf: the name in `locale`, else the default locale's, else the first non-empty one. */
export function pageNameOf(page: Pick<Page, "name">, locale: string, defaultLocale: string): string | null {
	for (const candidate of [locale, defaultLocale]) {
		const name = page.name[candidate];
		if (typeof name === "string" && name.trim() !== "") {
			return name;
		}
	}
	for (const name of Object.values(page.name)) {
		if (typeof name === "string" && name.trim() !== "") {
			return name;
		}
	}
	return null;
}

/**
 * PageRefList::resolve: the live pages a stored pages value names, in stored
 * order. Unknown, trashed and unpublished ids drop out, and so does a page
 * with no slug or no name in the locale, as a link to it would.
 */
export async function resolvePageRefs(site: Site, value: unknown, locale: string): Promise<PageRef[]> {
	return (await livePageRefs(site, value, locale)).map(({ ref }) => ref);
}

/** resolvePageRefs with each ref's page, for a caller that reads more of it. */
export async function livePageRefs(site: Site, value: unknown, locale: string): Promise<{ ref: PageRef; page: Page }[]> {
	const ids = pageIds(value);
	if (ids.length === 0) {
		return [];
	}
	const rows = await whereLive(site.pages.query().selectAll("tbtop_pages").where("tbtop_pages.id", "in", ids)).execute();
	const pages = new Map(rows.map((row) => [Number(row.id), pageFromRow(row)]));
	const paths = await site.pages.slugs.pathsFor(ids, locale);
	const fallback = await site.languages.defaultLocale();
	const out: { ref: PageRef; page: Page }[] = [];
	for (const id of ids) {
		const page = pages.get(id);
		const name = page === undefined || !isLive(page) ? null : pageNameOf(page, locale, fallback);
		const path = paths.get(id);
		if (page !== undefined && name !== null && path !== undefined) {
			out.push({ ref: { id: String(id), name, url: await site.url(path, locale) }, page });
		}
	}
	return out;
}

/** The type/subtype pairs a pages field accepts (PageTypeFilter::accepts); none means any page. */
export function acceptsType(pairs: string[], type: string, subtype: string | null): boolean {
	return pairs.length === 0 || pairs.includes(type) || (subtype !== null && pairs.includes(`${type}/${subtype}`));
}

/**
 * Pages whose name in one of `locales` contains `term`, ignoring case in every
 * script (PageNameSearch), in sort_order then id order, drafts included. The
 * names are matched here, not in SQL: a JSON path and a Unicode-aware LOWER()
 * differ on every dialect, and an admin search reads one site's pages.
 */
export async function searchPages(
	site: Site,
	options: { term: string; locales: string[]; limit: number; accepts?: (page: Page) => boolean },
): Promise<Page[]> {
	const rows = await site.pages.query().selectAll("tbtop_pages").orderBy("sort_order").orderBy("id").execute();
	const term = options.term.trim().toLowerCase();
	const out: Page[] = [];
	for (const row of rows) {
		const page = pageFromRow(row);
		if (options.accepts !== undefined && !options.accepts(page)) {
			continue;
		}
		if (term !== "" && !options.locales.some((locale) => matches(page.name[locale], term))) {
			continue;
		}
		out.push(page);
		if (out.length >= options.limit) {
			break;
		}
	}
	return out;
}

const matches = (name: unknown, term: string): boolean => typeof name === "string" && name.toLowerCase().includes(term);

/** Whether a page row with this id exists; `withTrashed` counts soft-deleted ones too (PageRefRule). */
export async function pageExists(site: Site, id: number, options: { withTrashed?: boolean } = {}): Promise<boolean> {
	const qb = options.withTrashed === true ? site.config.db.selectFrom("tbtop_pages") : site.pages.query();
	return (await qb.select("tbtop_pages.id").where("tbtop_pages.id", "=", id).executeTakeFirst()) !== undefined;
}

const natural = new Intl.Collator("en", { numeric: true, sensitivity: "base" });

/** PageRefIndex::sourcesQuery: the live pages whose pages field `field` picks `target`, of type or subtype `from` when given. */
function sourcesQuery(site: Site, target: Page, field: string, from: string | undefined) {
	const qb = whereLive(site.pages.query()).where("tbtop_pages.id", "in", (eb) =>
		eb.selectFrom("tbtop_page_refs").select("page_id").where("target_id", "=", target.id).where("field", "=", field),
	);
	return from === undefined ? qb : qb.where((eb) => eb.or([eb("tbtop_pages.type", "=", from), eb("tbtop_pages.subtype", "=", from)]));
}

/**
 * PageRefIndex::sources: the live pages whose page-type pages field `field`
 * picks `target`, only those of type or subtype `from` when given; by
 * sort_order, then name in `locale`, then id.
 */
export async function referencedBy(site: Site, target: Page | null, field: string, options: { from?: string; locale: string }): Promise<Page[]> {
	if (target === null) {
		return [];
	}
	const fallback = await site.languages.defaultLocale();
	const name = (page: Page) => pageNameOf(page, options.locale, fallback) ?? "";
	return (await sourcesQuery(site, target, field, options.from).selectAll("tbtop_pages").execute())
		.map(pageFromRow)
		.sort((a, b) => a.sortOrder - b.sortOrder || natural.compare(name(a), name(b)) || a.id - b.id);
}

/** referencedBy as the `{id, name, url}` list a pages field resolves to; a page without a name or slug in the locale drops out. */
export async function referencedByLinks(site: Site, target: Page | null, field: string, options: { from?: string; locale: string }): Promise<PageRef[]> {
	const pages = await referencedBy(site, target, field, options);
	return resolvePageRefs(site, pages.map((page) => page.id), options.locale);
}

/** What a paged archive takes: the type or subtype of the pages listed, the page size and, else the request's, the page number. */
export type PagedOptions = { from?: string; locale: string; perPage?: number; page?: number };

const DEFAULT_PER_PAGE = 20;

/**
 * Cms::referencedByLinksPaged: one page of referencedBy for an archive (a
 * tag's articles), newest first, as links. The page number is the request's
 * `?page=` unless given; page 2 on gets a `?page=N` canonical and a page past
 * the end is a 404.
 */
export async function referencedByLinksPaged(site: Site, target: Page | null, field: string, options: PagedOptions): Promise<PagedRefs<PageRef>> {
	if (target === null) {
		return emptyPage(options);
	}
	return linksOf(site, sourcesQuery(site, target, field, options.from), options);
}

/** Cms::childrenLinksPaged: one page of a page's live children (a category's articles), the same way. */
export async function childrenLinksPaged(site: Site, parent: Page | null, options: PagedOptions): Promise<PagedRefs<PageRef>> {
	if (parent === null) {
		return emptyPage(options);
	}
	const children = whereLive(site.pages.query()).where("tbtop_pages.parent_id", "=", parent.id);
	const from = options.from;
	return linksOf(site, from === undefined ? children : children.where((eb) => eb.or([eb("tbtop_pages.type", "=", from), eb("tbtop_pages.subtype", "=", from)])), options);
}

async function linksOf(site: Site, query: Parameters<typeof takePage>[1], options: PagedOptions): Promise<PagedRefs<PageRef>> {
	const { pages, pagination } = await takePage(site.paging, query, {
		perPage: options.perPage ?? DEFAULT_PER_PAGE,
		...(options.page === undefined ? {} : { page: options.page }),
	});
	return { items: await resolvePageRefs(site, pages.map((page) => page.id), options.locale), pagination };
}

const emptyPage = (options: PagedOptions): PagedRefs<PageRef> => ({
	items: [],
	pagination: { page: 1, pages: 1, total: 0, perPage: options.perPage ?? DEFAULT_PER_PAGE },
});
