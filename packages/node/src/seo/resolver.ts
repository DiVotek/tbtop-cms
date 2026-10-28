import { isLive, type Page } from "../content/page";
import { breadcrumbs, type Crumb } from "../frontend/breadcrumbs";
import type { Site } from "../site";
import { SiteIdentity } from "./identity";
import { localized } from "./localized";
import {
	canonicalOverride,
	description,
	identityId,
	identityLogo,
	ownDescription,
	ownImage,
	ownTitle,
	type SchemaContext,
} from "./schemaContext";
import { FALLBACK_SCHEMA, type OpenGraph, type SchemaTypeDef } from "./schemaTypes";
import { isoDay } from "../support/values";

export type Alternate = { locale: string; url: string };

/** The `seo` page prop: everything a host puts in `<head>` (SeoResolver.php). */
export type Seo = {
	title: string;
	description: string;
	canonical: string;
	ogImage: string | null;
	ogImageAlt: string | null;
	ogImageType: string | null;
	ogImageWidth: number | null;
	ogImageHeight: number | null;
	ogTitle: string;
	ogDescription: string;
	noindex: boolean;
	siteName: string;
	ogType: string;
	ogUrl: string;
	ogLocale: string | null;
	ogLocaleAlternates: string[];
	articlePublishedTime: string | null;
	articleModifiedTime: string | null;
	articleAuthor: string | null;
	twitterCard: string;
	alternates: Alternate[];
	xDefault: string | null;
	ownTitle: string;
	ownDescription: string;
	favicon: string | null;
	verificationGoogle: string | null;
	verificationBing: string | null;
	/** The Google Tag Manager container (`seo.gtm_container_id`), a well-formed `GTM-…` id or null. */
	gtmContainerId: string | null;
	jsonLd: Record<string, unknown>[];
};

/** `seo.indexing_enabled`: a missing or null value means on. */
export async function indexingEnabled(site: Site): Promise<boolean> {
	const value = await site.settings.get("seo.indexing_enabled", true);
	return value === null || Boolean(value);
}

/** SchemaSelector: the stored `seo.schema.type` when the page type offers it, else the type's first. */
export function selectSchema(site: Site, page: Page): SchemaTypeDef {
	if (!site.pageTypes.has(page.type)) {
		return site.schemaTypes.get(FALLBACK_SCHEMA);
	}
	const available = site.pageTypes.schemaTypesFor(page).filter((key) => site.schemaTypes.has(key));
	const schema = page.seo.schema;
	const stored = typeof schema === "object" && schema !== null ? (schema as Record<string, unknown>).type : null;
	const key = typeof stored === "string" && available.includes(stored) ? stored : (available[0] ?? FALLBACK_SCHEMA);
	return site.schemaTypes.get(key);
}

export async function resolveSeo(
	site: Site,
	page: Page,
	locale: string,
	ancestors: Crumb[],
	ctx: SchemaContext,
	blockNodes: Record<string, unknown>[] = [],
): Promise<Seo> {
	const metaTitle = await ownTitle(site, page, locale);
	const metaDescription = await description(site, page, locale);
	const ogTitle = localized(page.seo.og_title, locale) || metaTitle;
	const siteName = await site.settings.string("site.name");
	const alternates = await pageAlternates(site, page);
	const schema = selectSchema(site, page);
	const og: OpenGraph = schema.openGraph?.(ctx) ?? { type: "website" };
	const defaultLocale = await site.languages.defaultLocale();
	return {
		title: await renderTitle(site, page, metaTitle, locale, ancestors),
		description: await renderDescription(site, page, metaDescription, locale, ancestors),
		canonical: ctx.url,
		ogImage: ctx.image,
		ogImageAlt: ctx.imageAlt,
		ogImageType: ctx.imageMime,
		ogImageWidth: ctx.imageWidth,
		ogImageHeight: ctx.imageHeight,
		ogTitle,
		ogDescription: ctx.description,
		noindex: !page.isIndexable || !(await indexingEnabled(site)),
		siteName,
		ogType: og.type,
		ogUrl: ctx.url,
		ogLocale: await site.languages.ogLocale(locale),
		ogLocaleAlternates: await ogLocales(site, locale, alternates),
		articlePublishedTime: og.publishedTime ?? null,
		articleModifiedTime: og.modifiedTime ?? null,
		articleAuthor: og.author ?? null,
		twitterCard: await twitterCard(site, ctx.image),
		alternates,
		xDefault: alternates.find((alternate) => alternate.locale === defaultLocale)?.url ?? null,
		ownTitle: metaTitle,
		ownDescription: await ownDescription(site, page, locale),
		favicon: await favicon(site),
		verificationGoogle: verification(await site.settings.get("seo.verification.google", ""), "google-site-verification"),
		verificationBing: verification(await site.settings.get("seo.verification.bing", ""), "msvalidate.01"),
		gtmContainerId: gtmContainerId(await site.settings.get("seo.gtm_container_id", "")),
		jsonLd: await jsonLd(site, page, locale, siteName, ogTitle, schema, ctx, blockNodes),
	};
}

/** SeoAlternates::forPage: a URL per frontend locale the page has a path in, minus locales canonicalized elsewhere. */
export async function pageAlternates(site: Site, page: Page, locales?: string[]): Promise<Alternate[]> {
	const codes = locales ?? (await site.languages.frontendActive()).map((row) => row.locale);
	const paths = await site.pages.slugs.allFor(page.id);
	const out: Alternate[] = [];
	for (const code of codes) {
		const path = paths[code];
		if (path === undefined) {
			continue;
		}
		const url = site.absolute(await site.url(path, code));
		const override = canonicalOverride(page, code);
		if (override === null || normalizeUrl(override) === normalizeUrl(url)) {
			out.push({ locale: code, url });
		}
	}
	return out;
}

function normalizeUrl(raw: string): string {
	try {
		const url = new URL(raw);
		const path = url.pathname.endsWith("/") ? url.pathname.slice(0, -1) : url.pathname;
		const auth = url.username !== "" || url.password !== "" ? `${url.username}${url.password ? `:${url.password}` : ""}@` : "";
		return `${url.protocol}//${auth}${url.host}${path}${url.search}${url.hash}`;
	} catch {
		return raw;
	}
}

async function ogLocales(site: Site, locale: string, alternates: Alternate[]): Promise<string[]> {
	const out: string[] = [];
	for (const { locale: code } of alternates) {
		const og = code === locale ? null : await site.languages.ogLocale(code);
		if (og !== null) {
			out.push(og);
		}
	}
	return out;
}

async function twitterCard(site: Site, image: string | null): Promise<string> {
	const choice = await site.settings.string("seo.twitter_card", "auto");
	if (choice === "summary" || choice === "summary_large_image") {
		return choice;
	}
	return image !== null ? "summary_large_image" : "summary";
}

async function favicon(site: Site): Promise<string | null> {
	const ref = await site.settings.get("site.favicon");
	return typeof ref === "string" && ref !== "" ? site.mediaUrl(ref) : null;
}

/** VerificationToken: a bare token, or the content of a pasted `<meta name=…>` tag. */
export function verification(raw: unknown, metaName: string): string | null {
	if (typeof raw !== "string") {
		return null;
	}
	let token = raw.trim();
	const tag = /<meta\b[^>]*>/i.exec(token)?.[0];
	if (tag !== undefined) {
		const escaped = metaName.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
		const content = /\bcontent\s*=\s*(["'])(.*?)\1/i.exec(tag);
		if (new RegExp(`\\bname\\s*=\\s*(["'])${escaped}\\1`, "i").test(tag) && content !== null) {
			token = (content[2] ?? "").trim();
		}
	}
	return token !== "" && /^[A-Za-z0-9_\-+/=]{1,512}$/.test(token) ? token : null;
}

/**
 * GtmContainerId::extract: a bare `GTM-…` id, or the first one in a pasted
 * install snippet (recognised by its googletagmanager.com reference),
 * upper-cased; anything else comes back trimmed, for the admin to see.
 */
export function extractGtmId(raw: string): string {
	const trimmed = raw.trim();
	if (/^GTM-[A-Z0-9]+$/i.test(trimmed)) {
		return trimmed.toUpperCase();
	}
	const mined = /googletagmanager\.com/i.test(trimmed) ? /GTM-[A-Z0-9]+/i.exec(trimmed)?.[0] : undefined;
	return mined === undefined ? trimmed : mined.toUpperCase();
}

/** GtmContainerId::fromSettings: extracted on read too; only a well-formed id reaches the markup. */
export function gtmContainerId(raw: unknown): string | null {
	if (typeof raw !== "string") {
		return null;
	}
	const id = extractGtmId(raw);
	return /^GTM-[A-Z0-9]+$/.test(id) ? id : null;
}

/** SeoTemplates: the subtype's, then the type's, then the site-wide template. */
async function template(site: Site, page: Page, slot: "title" | "description", locale: string): Promise<string> {
	for (const key of [page.subtype, page.type]) {
		if (key === null || key === "") {
			continue;
		}
		const perType = localized(await site.settings.get(`seo.type_templates.${key}.${slot}`), locale);
		if (perType !== "") {
			return perType;
		}
	}
	return localized(await site.settings.get(`seo.${slot}_template`), locale);
}

/**
 * SeoResolver::renderedTitle / renderedDescription: what `<title>` and the
 * meta description show, for the report's duplicate checks; the ancestors
 * are read only when a template uses `{parent}`.
 */
export async function renderedTitle(site: Site, page: Page, locale: string, ancestors: () => Promise<Crumb[]>): Promise<string> {
	const tpl = await template(site, page, "title", locale);
	return renderTitle(site, page, await ownTitle(site, page, locale), locale, tpl.includes("{parent}") ? await ancestors() : []);
}

export async function renderedDescription(site: Site, page: Page, locale: string, ancestors: () => Promise<Crumb[]>): Promise<string> {
	const tpl = await template(site, page, "description", locale);
	return renderDescription(site, page, await description(site, page, locale), locale, tpl.includes("{parent}") ? await ancestors() : []);
}

async function renderTitle(site: Site, page: Page, base: string, locale: string, ancestors: Crumb[]): Promise<string> {
	const tpl = await template(site, page, "title", locale);
	if (tpl === "") {
		const suffix = await site.settings.string("seo.title_suffix");
		return suffix === "" ? base : base === "" ? suffix : `${base} ${suffix}`;
	}
	return render(site, tpl, { "{title}": base }, locale, page, ancestors);
}

async function renderDescription(site: Site, page: Page, base: string, locale: string, ancestors: Crumb[]): Promise<string> {
	const tpl = await template(site, page, "description", locale);
	return tpl === "" ? base : render(site, tpl, { "{description}": base }, locale, page, ancestors);
}

async function render(
	site: Site,
	tpl: string,
	vars: Record<string, string>,
	locale: string,
	page: Page,
	ancestors: Crumb[],
): Promise<string> {
	const separator = localized(await site.settings.get("seo.title_separator", "—"), locale) || "—";
	const all: Record<string, string> = {
		...vars,
		"{site_name}": await site.settings.string("site.name"),
		"{sep}": separator,
		"{parent}": ancestors.at(-1)?.label ?? "",
		"{date}": page.publishedAt !== null && isLive(page) ? longDate(page.publishedAt, locale, site.config.timezone ?? "UTC") : "",
	};
	const out = strtr(tpl, all);
	const sep = escapeRegExp(separator);
	const collapsed = out.replace(/\s+/gu, " ").trim();
	const deduped = collapsed.replace(new RegExp(`${sep}(?:\\s*${sep})+`, "gu"), separator);
	return deduped.replace(new RegExp(`^(?:${sep}\\s*)+|(?:\\s*${sep})+$`, "gu"), "").trim();
}

const escapeRegExp = (text: string) => text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

/** PHP strtr: one pass, longest key first, so a substituted value is never scanned again. */
function strtr(text: string, pairs: Record<string, string>): string {
	const keys = Object.keys(pairs).filter((key) => key !== "").sort((a, b) => b.length - a.length);
	return keys.length === 0 ? text : text.replace(new RegExp(keys.map(escapeRegExp).join("|"), "g"), (key) => pairs[key] ?? key);
}

/** Carbon isoFormat('LL'): the locale's long date. */
function longDate(date: Date, locale: string, timeZone: string): string {
	try {
		return new Intl.DateTimeFormat(locale, { year: "numeric", month: "long", day: "numeric", timeZone }).format(date);
	} catch {
		// A locale tag Intl rejects: the ISO day, still in the site's zone.
		return isoDay(date, timeZone);
	}
}

async function jsonLd(
	site: Site,
	page: Page,
	locale: string,
	siteName: string,
	ogTitle: string,
	schema: SchemaTypeDef,
	ctx: SchemaContext,
	blockNodes: Record<string, unknown>[],
): Promise<Record<string, unknown>[]> {
	const out: Record<string, unknown>[] = [];
	const identity = await identityNode(site, locale);
	if (siteName !== "") {
		// The same @id on every page makes it one WebSite to a crawler, published by the identity.
		const publisher = identity === null ? {} : { publisher: { "@id": identityId(site) } };
		out.push({ "@context": "https://schema.org", "@type": "WebSite", "@id": `${site.absolute("/")}#website`, name: siteName, url: site.absolute("/"), ...publisher });
	}
	const trail = await breadcrumbs(site, page, locale);
	const current = trail.at(-1)?.label ?? "";
	const items = [
		...trail.slice(0, -1).map((crumb) => ({ label: crumb.label, url: site.absolute(crumb.url) })),
		{ label: current !== "" ? current : ogTitle, url: ctx.url },
	];
	if (items.length >= 2) {
		out.push({
			"@context": "https://schema.org",
			"@type": "BreadcrumbList",
			itemListElement: items.map((item, index) => ({ "@type": "ListItem", position: index + 1, name: item.label, item: item.url })),
		});
	}
	out.push(...(await schema.build(ctx)), ...blockNodes);
	if (identity !== null) {
		out.push(identity);
	}
	const extend = site.config.seo?.extend;
	return extend === undefined ? out : extend(out, ctx);
}

async function identityNode(site: Site, locale: string): Promise<Record<string, unknown> | null> {
	const identity = new SiteIdentity(site.settings, site.contacts);
	const type = await identity.type();
	const name = await identity.name();
	if (type === "none" || name === "") {
		return null;
	}
	const node: Record<string, unknown> = {
		"@context": "https://schema.org",
		"@type": (await identity.schemaType()) ?? SiteIdentity.schemaTypeFor(type),
		"@id": identityId(site),
		name,
		url: site.absolute("/"),
	};
	const logo = await identityLogo(site);
	if ((await identity.isOrganisation()) && logo !== null) {
		node.logo = logo;
	}
	Object.assign(node, await identity.properties(locale));
	const sameAs = await identity.sameAs();
	if (sameAs.length > 0) {
		node.sameAs = sameAs;
	}
	return node;
}

export { ownImage };
