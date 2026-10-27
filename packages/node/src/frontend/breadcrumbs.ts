import { firstText, isLive, type Page } from "../content/page";
import type { Site } from "../site";

export type Crumb = { label: string; path: string; url: string };

/** Breadcrumbs::forPage: home, the live ancestors, the page; fewer than two crumbs is no trail. */
export async function breadcrumbs(site: Site, page: Page, locale: string): Promise<Crumb[]> {
	const chain = await site.pages.liveAncestors(page);
	const home = await site.pages.home();
	if (home !== null && isLive(home) && home.id !== page.id && !chain.some((ancestor) => ancestor.id === home.id)) {
		chain.unshift(home);
	}
	chain.push(page);
	return chain.length < 2 ? [] : crumbs(site, chain, locale);
}

/** The live ancestors as crumbs, without home or the page (the SEO `{parent}` source). */
export async function ancestorCrumbs(site: Site, page: Page, locale: string): Promise<Crumb[]> {
	return crumbs(site, await site.pages.liveAncestors(page), locale);
}

async function crumbs(site: Site, pages: Page[], locale: string): Promise<Crumb[]> {
	if (pages.length === 0) {
		return [];
	}
	const fallback = await site.languages.defaultLocale();
	const paths = await site.pages.slugs.pathsFor(
		pages.map((page) => page.id),
		locale,
	);
	const out: Crumb[] = [];
	for (const page of pages) {
		const path = paths.get(page.id) ?? "";
		out.push({ label: firstText(page, ["name"], locale, fallback), path, url: await site.url(path, locale) });
	}
	return out;
}
