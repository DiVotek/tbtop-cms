import { firstText, isLive, type Page } from "../content/page";
import type { Site } from "../site";
import { atom } from "../support/values";
import { SiteIdentity } from "./identity";
import { localized } from "./localized";

/** What a schema type builds its JSON-LD from (SchemaContext.php). */
export type SchemaContext = {
	page: Page;
	locale: string;
	site: Site;
	name: string;
	description: string;
	url: string;
	image: string | null;
	imageAlt: string | null;
	imageMime: string | null;
	imageWidth: number | null;
	imageHeight: number | null;
	author: Record<string, unknown> | null;
	publisher: Record<string, unknown> | null;
};

type ImageDetails = { url: string; alt: string | null; mime: string | null; width: number | null; height: number | null };

/** The shared head of every schema node: type, name, url, and what is known of description, image, dates. */
export function baseNode(ctx: SchemaContext, type: string): Record<string, unknown> {
	const node: Record<string, unknown> = { "@context": "https://schema.org", "@type": type, name: ctx.name, url: ctx.url };
	if (ctx.description !== "") {
		node.description = ctx.description;
	}
	if (ctx.image !== null && ctx.image !== "") {
		node.image = ctx.image;
	}
	const published = publishedAt(ctx.page);
	if (published !== null) {
		node.datePublished = published;
	}
	const modified = modifiedAt(ctx.page);
	if (modified !== null) {
		node.dateModified = modified;
	}
	return node;
}

export const publishedAt = (page: Page): string | null =>
	page.publishedAt !== null && isLive(page) ? atom(page.publishedAt) : null;

export const modifiedAt = (page: Page): string | null => (page.updatedAt === null ? null : atom(page.updatedAt));

/** PageOwnSeo::title: seo.title, else title, else name. */
export async function ownTitle(site: Site, page: Page, locale: string): Promise<string> {
	const fallback = await site.languages.defaultLocale();
	return (
		localized(page.seo.title, locale) ||
		firstText(page, ["title"], locale, fallback) ||
		firstText(page, ["name"], locale, fallback)
	);
}

/** PageOwnSeo::description: seo.description, else summary. */
export async function ownDescription(site: Site, page: Page, locale: string): Promise<string> {
	return localized(page.seo.description, locale) || firstText(page, ["summary"], locale, await site.languages.defaultLocale());
}

/** The description the page shows: its own, else `seo.default_description`. */
export async function description(site: Site, page: Page, locale: string): Promise<string> {
	return (await ownDescription(site, page, locale)) || localized(await site.settings.get("seo.default_description", ""), locale);
}

/** A per-locale canonical override, kept only when it is an absolute http(s) URL. */
export function canonicalOverride(page: Page, locale: string): string | null {
	const override = localized(page.seo.canonical, locale);
	if (override === "") {
		return null;
	}
	try {
		const url = new URL(override);
		return (url.protocol === "http:" || url.protocol === "https:") && url.hostname !== "" ? override : null;
	} catch {
		return null;
	}
}

function imageDetails(site: Site, ref: string): ImageDetails | null {
	const details = site.media(ref);
	if (details === null) {
		return null;
	}
	const hasSize = (details.width ?? 0) > 0 && (details.height ?? 0) > 0;
	return {
		url: site.absolute(details.url),
		alt: details.alt !== undefined && details.alt !== null && details.alt !== "" ? details.alt : null,
		mime: details.mime?.startsWith("image/") ? details.mime : null,
		width: hasSize ? (details.width ?? null) : null,
		height: hasSize ? (details.height ?? null) : null,
	};
}

/** The page's own og image: `seo.og_image`, else `image`. */
export function ownImage(site: Site, page: Page): ImageDetails | null {
	const override = page.seo.og_image;
	if (typeof override === "string" && override !== "") {
		return imageDetails(site, override);
	}
	return page.image !== null && page.image !== "" ? imageDetails(site, page.image) : null;
}

const namesImage = (page: Page): boolean =>
	(typeof page.seo.og_image === "string" && page.seo.og_image !== "") || (page.image !== null && page.image !== "");

/** SchemaContextFactory::make. */
export async function schemaContext(site: Site, page: Page, locale: string): Promise<SchemaContext> {
	const fallback = await site.languages.defaultLocale();
	const global = await site.settings.get("seo.default_og_image");
	const image = namesImage(page) ? ownImage(site, page) : typeof global === "string" && global !== "" ? imageDetails(site, global) : null;
	const metaTitle = await ownTitle(site, page, locale);
	const ogTitle = localized(page.seo.og_title, locale) || metaTitle;
	const label = firstText(page, ["title", "name"], locale, fallback);
	return {
		page,
		locale,
		site,
		name: label !== "" ? label : ogTitle,
		description: localized(page.seo.og_description, locale) || (await description(site, page, locale)),
		url: await canonical(site, page, locale),
		image: image?.url ?? null,
		imageAlt: image?.alt ?? null,
		imageMime: image?.mime ?? null,
		imageWidth: image?.width ?? null,
		imageHeight: image?.height ?? null,
		author: await identityAuthor(site),
		publisher: await identityPublisher(site),
	};
}

async function canonical(site: Site, page: Page, locale: string): Promise<string> {
	return (
		canonicalOverride(page, locale) ??
		site.absolute(await site.url((await site.pages.slugs.pathFor(page.id, locale)) ?? "", locale))
	);
}

export const identityId = (site: Site): string => `${site.absolute("/")}#identity`;

async function identityAuthor(site: Site): Promise<Record<string, unknown> | null> {
	const identity = new SiteIdentity(site.settings);
	const schemaType = await identity.schemaType();
	const name = await identity.name();
	if (name === "" || schemaType === null || !((await identity.type()) === "person" || (await identity.isOrganisation()))) {
		return null;
	}
	return { "@type": schemaType, name, "@id": identityId(site) };
}

async function identityPublisher(site: Site): Promise<Record<string, unknown> | null> {
	const identity = new SiteIdentity(site.settings);
	const schemaType = await identity.schemaType();
	const name = await identity.name();
	if (!(await identity.isOrganisation()) || schemaType === null || name === "") {
		return null;
	}
	const publisher: Record<string, unknown> = { "@type": schemaType, name, url: site.absolute("/") };
	const logo = await identityLogo(site);
	if (logo !== null) {
		publisher.logo = logo;
	}
	publisher["@id"] = identityId(site);
	return publisher;
}

export async function identityLogo(site: Site): Promise<string | null> {
	let ref = await site.settings.get("seo.identity.logo");
	if (typeof ref !== "string" || ref === "") {
		ref = await site.settings.get("site.logo");
	}
	const url = typeof ref === "string" && ref !== "" ? site.mediaUrl(ref) : null;
	return url === null ? null : site.absolute(url);
}
