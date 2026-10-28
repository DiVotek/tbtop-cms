import { type ExpressionBuilder, type SelectQueryBuilder, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { Page } from "./page";
import { whereLive } from "./pages";
import type { Site } from "../site";

type PagesQuery = SelectQueryBuilder<CmsDatabase, "tbtop_pages", object>;
type Refs = ExpressionBuilder<CmsDatabase, "tbtop_pages">;

/**
 * EmptyArchives: an archive page (its type names `archiveOf`) that no live
 * page picks through that field, a tag nothing is tagged with. It is
 * noindex and the sitemap leaves it out, so empty tag pages do not dilute
 * the site.
 */
export async function isEmptyArchive(site: Site, page: Page): Promise<boolean> {
	const field = site.pageTypes.ownType(page)?.archiveOf ?? null;
	if (field === null) {
		return false;
	}
	const row = await referrers(site, site.config.db.selectFrom("tbtop_page_refs as r"), field)
		.select("r.page_id")
		.where("r.target_id", "=", page.id)
		.limit(1)
		.executeTakeFirst();
	return row === undefined;
}

/** Leaves empty archives out of a pages query (the sitemap's). */
export function excludeEmptyArchives<Q extends PagesQuery>(site: Site, query: Q): Q {
	let out = query;
	for (const type of site.pageTypes.all()) {
		const field = type.archiveOf;
		if (field === null) {
			continue;
		}
		const isBase = type.base === type.key;
		// The rows this type speaks for: its subtype's, or the base's without a registered subtype of its own.
		const subtypes = isBase ? site.pageTypes.subtypesOf(type.key).map((sub) => sub.key) : [];
		out = out.where((eb) =>
			eb.or([
				isBase ? eb.or([eb("tbtop_pages.type", "!=", type.key), ...(subtypes.length === 0 ? [] : [eb("tbtop_pages.subtype", "in", subtypes)])]) : eb.or([eb("tbtop_pages.subtype", "is", null), eb("tbtop_pages.subtype", "!=", type.key)]),
				eb.exists(referrers(site, (eb as Refs).selectFrom("tbtop_page_refs as r"), field).select("r.page_id").where("r.target_id", "=", sql.ref<number>("tbtop_pages.id"))),
			]),
		) as Q;
	}
	return out;
}

/** Index rows of live pages (whereLive, the listings' own rule) picking through `field`. */
function referrers<Q extends SelectQueryBuilder<CmsDatabase & { r: CmsDatabase["tbtop_page_refs"] }, "r", object>>(site: Site, query: Q, field: string) {
	return query.where("r.field", "=", field).where("r.page_id", "in", whereLive(site.pages.query()).select("tbtop_pages.id"));
}
