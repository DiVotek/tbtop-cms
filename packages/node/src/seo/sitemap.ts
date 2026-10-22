import { sql } from "kysely";
import { whereLive } from "../content/pages";
import { pageFromRow } from "../content/page";
import type { Site } from "../site";
import { atom } from "../support/values";
import { ownImage, pageAlternates } from "./resolver";

const MAX_PER_CHUNK = 50000;

type Entry = { loc: string; lastmod: string; priority: string; image: string | null; alternates: Record<string, string> };

const xml = (text: string): string =>
	text.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&apos;");

async function perChunk(site: Site): Promise<number> {
	const stored = Number(await site.settings.get("seo.sitemap_per_chunk"));
	if (Number.isInteger(stored) && stored >= 1) {
		return Math.min(stored, MAX_PER_CHUNK);
	}
	return Math.max(1, Math.min(site.config.sitemapPerChunk ?? 1000, MAX_PER_CHUNK));
}

function query(site: Site) {
	return whereLive(site.pages.query()).where("is_indexable", "=", true).where("error_class", "is", null);
}

async function pagesPerChunk(site: Site): Promise<{ locales: string[]; size: number }> {
	const locales = (await site.languages.frontendActive()).map((row) => row.locale);
	return { locales, size: Math.max(1, Math.floor((await perChunk(site)) / Math.max(1, locales.length))) };
}

export async function chunkCount(site: Site): Promise<number> {
	const { locales, size } = await pagesPerChunk(site);
	if (locales.length === 0) {
		return 1;
	}
	const row = await query(site)
		.select(sql<number>`count(*)`.as("count"))
		.executeTakeFirst();
	return Math.max(1, Math.ceil(Number(row?.count ?? 0) / size));
}

/** SitemapEntryBuilder: one entry per live, indexable page and locale, with its alternates. */
export async function sitemapEntries(site: Site, chunk: number): Promise<Entry[]> {
	const { locales, size } = await pagesPerChunk(site);
	if (locales.length === 0) {
		return [];
	}
	const rows = await query(site)
		.selectAll("tbtop_pages")
		.orderBy("id")
		.limit(size)
		.offset((Math.max(1, chunk) - 1) * size)
		.execute();
	const defaultLocale = await site.languages.defaultLocale();
	const entries: Entry[] = [];
	for (const page of rows.map(pageFromRow)) {
		const alternates = Object.fromEntries((await pageAlternates(site, page, locales)).map((a) => [a.locale, a.url]));
		if (Object.keys(alternates).length === 0) {
			continue;
		}
		const paths = await site.pages.slugs.allFor(page.id);
		const path = paths[defaultLocale] ?? Object.values(paths)[0] ?? null;
		const depth = (path ?? "").replace(/^\/+|\/+$/g, "").split("/").length - 1;
		const priority = (path === "" ? 1 : Math.max(0.3, 0.8 - depth * 0.2)).toFixed(1);
		const base = {
			lastmod: page.updatedAt === null ? "" : atom(page.updatedAt),
			priority,
			image: ownImage(site, page)?.url ?? null,
			alternates,
		};
		for (const locale of locales) {
			const loc = alternates[locale];
			if (loc !== undefined) {
				entries.push({ loc, ...base });
			}
		}
	}
	return entries;
}

export async function sitemapChunkXml(site: Site, chunk: number): Promise<string> {
	const defaultLocale = await site.languages.defaultLocale();
	const urls = (await sitemapEntries(site, chunk)).map((entry) => {
		const parts = [`<loc>${xml(entry.loc)}</loc>`];
		if (entry.lastmod !== "") {
			parts.push(`<lastmod>${entry.lastmod}</lastmod>`);
		}
		parts.push(`<priority>${entry.priority}</priority>`);
		const alternate = (hreflang: string, href: string) =>
			`<xhtml:link rel="alternate" hreflang="${xml(hreflang)}" href="${xml(href)}"/>`;
		for (const [locale, href] of Object.entries(entry.alternates)) {
			parts.push(alternate(locale, href));
		}
		const xDefault = entry.alternates[defaultLocale];
		if (defaultLocale !== "" && xDefault !== undefined) {
			parts.push(alternate("x-default", xDefault));
		}
		if (entry.image !== null && entry.image !== "") {
			parts.push(`<image:image><image:loc>${xml(entry.image)}</image:loc></image:image>`);
		}
		return `<url>${parts.join("")}</url>`;
	});
	return (
		'<?xml version="1.0"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' +
		`${urls.join("")}</urlset>\n`
	);
}

export async function sitemapIndexXml(site: Site): Promise<string> {
	const count = await chunkCount(site);
	const lastmod = atom(new Date());
	const entries = Array.from(
		{ length: count },
		(_, i) => `<sitemap><loc>${xml(site.absolute(`/sitemap-${i + 1}.xml`))}</loc><lastmod>${lastmod}</lastmod></sitemap>`,
	);
	return `<?xml version="1.0"?>\n<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${entries.join("")}</sitemapindex>\n`;
}
