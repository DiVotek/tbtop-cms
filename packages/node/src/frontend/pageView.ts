import { resolvePageFields } from "../blocks/resolver";
import { type Page, translation, translationText } from "../content/page";
import { richtextHtml } from "../content/richtext";
import { modifiedAt, publishedAt } from "../seo/schemaContext";
import type { Site } from "../site";

/** A page's own columns in one locale, as `props.page` carries them (fallback: the default locale). */
export type PageColumns = {
	id: number;
	type: string;
	subtype: string | null;
	status: string;
	name: string | null;
	title: string | null;
	heading: string | null;
	summary: string | null;
	body: string;
	image: string | null;
	/** The dates the JSON-LD states (ISO 8601): published only once live, else null. */
	publishedAt: string | null;
	updatedAt: string | null;
};

/** A page in a host's list (site.pageViews): `props.page` plus its URL; `fields` only when asked. */
export type PageView = PageColumns & { url: string | null; fields: Record<string, unknown> };

export function pageColumns(site: Site, page: Page, locale: string, fallback: string): PageColumns {
	return {
		id: page.id,
		type: page.type,
		subtype: page.subtype,
		status: page.status,
		name: translationText(page, "name", locale, fallback),
		title: translationText(page, "title", locale, fallback),
		heading: translationText(page, "heading", locale, fallback),
		summary: translationText(page, "summary", locale, fallback),
		body: richtextHtml(translation(page, "body", locale, fallback)),
		image: page.image !== null && page.image !== "" ? site.mediaUrl(page.image) : null,
		publishedAt: publishedAt(page),
		updatedAt: modifiedAt(page),
	};
}

/**
 * Pages the host listed itself (whereLive + pageFromRow) as `props.page`
 * objects with a URL, in the given order: URLs in one query, fields only with
 * `fields: true` (one resolve per page, as ctx.pages). It filters nothing.
 */
export async function pageViews(site: Site, pages: Page[], locale: string, options: { fields?: boolean } = {}): Promise<PageView[]> {
	const fallback = await site.languages.defaultLocale();
	const paths = await site.pages.slugs.pathsFor(
		pages.map((page) => page.id),
		locale,
	);
	const fields = options.fields === true ? await resolvePageFields(site, pages, locale) : pages.map(() => ({}));
	const out: PageView[] = [];
	for (const [i, page] of pages.entries()) {
		const path = paths.get(page.id);
		out.push({ ...pageColumns(site, page, locale, fallback), url: path === undefined ? null : await site.url(path, locale), fields: fields[i] ?? {} });
	}
	return out;
}
