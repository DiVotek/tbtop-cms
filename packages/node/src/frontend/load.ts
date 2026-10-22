import { assembleSpec, bindForms, type RenderSpec, type ResolvedBlock, resolveBlocks, validateProps } from "../blocks/resolver";
import { SLOTS, type Slot } from "../content/layouts";
import { isLive, type Page, translation, translationText } from "../content/page";
import { isExternal } from "../content/redirects";
import { richtextHtml } from "../content/richtext";
import { resolveSeo, type Seo } from "../seo/resolver";
import { schemaContext } from "../seo/schemaContext";
import type { Site } from "../site";
import type { Jsonify } from "../support/values";
import { ancestorCrumbs, breadcrumbs, type Crumb } from "./breadcrumbs";

/** The shared `cms` prop (ShareCmsProps): site settings, locales, the resolved header and footer. */
export type SharedProps = Jsonify<SharedShape>;

/** The props of a CMS page, all JSON: a host passes them through its loader as they are. */
export type PageProps = Jsonify<PageShape>;

type SharedShape = {
	settings: Record<string, unknown>;
	locale: string;
	defaultLocale: string;
	languages: { locale: string; name: string; is_default: boolean }[];
	layout: Record<Slot, ResolvedBlock[]>;
	debug: boolean;
	strings: { previewBanner: string };
};

type PageShape = {
	page: {
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
		fields: Record<string, unknown>;
	};
	render: RenderSpec;
	seo: Seo;
	breadcrumbs: Crumb[];
	path: string;
	isPreview: boolean;
	cms: SharedShape;
};

export type LoadResult =
	| { type: "page"; status: 200; headers: Record<string, string>; props: PageProps }
	| { type: "redirect"; status: number; location: string }
	| { type: "notFound"; status: 404; locale: string; path: string; cms: SharedProps };

/**
 * rawurldecode over the whole path, as Laravel's decodedPath(): `%2F` becomes a
 * segment separator. It never throws: an escape that is not UTF-8 stays as
 * written, so it matches no slug.
 */
function decodePath(path: string): string {
	return path.replace(/(?:%[0-9a-fA-F]{2})+/g, (run) => {
		try {
			return decodeURIComponent(run);
		} catch {
			return run;
		}
	});
}

/**
 * SetLanguage: a default-locale prefix 301s to the bare path; a frontend
 * locale prefix is stripped and picked; anything else is the default locale.
 */
export async function routeLocale(
	site: Site,
	url: URL,
): Promise<{ type: "redirect"; location: string } | { type: "route"; locale: string; path: string }> {
	const path = decodePath(url.pathname).replace(/^\/+|\/+$/g, "");
	const segments = path.split("/").filter((segment) => segment !== "");
	const first = segments[0] ?? "";
	const defaultLocale = await site.languages.defaultLocale();
	if (first !== "" && first === defaultLocale) {
		return { type: "redirect", location: canonical(segments.slice(1), url.search) };
	}
	if (first !== "" && (await site.languages.frontendActive()).some((row) => row.locale === first)) {
		return { type: "route", locale: first, path: segments.slice(1).join("/") };
	}
	return { type: "route", locale: defaultLocale, path: segments.join("/") };
}

/**
 * SetLanguage::canonical: empty segments drop and each one is encoded again,
 * so a decoded `/` or `\` can never make the Location protocol-relative.
 */
function canonical(segments: string[], search: string): string {
	const rest = `/${segments
		.filter((segment) => segment !== "")
		.map(encodeURIComponent)
		.join("/")}`;
	return search === "" ? rest : `${rest}${search}`;
}

/** PageController: the page at the request URL, a redirect, or not found. */
export async function loadPage(site: Site, request: Request, options: { preview: boolean }): Promise<LoadResult> {
	const url = new URL(request.url);
	const route = await routeLocale(site, url);
	if (route.type === "redirect") {
		return { type: "redirect", status: 301, location: route.location };
	}
	const { locale, path } = route;
	const page = await resolvePage(site, path, locale, options.preview);
	if (page === null) {
		const redirect = await site.redirects.resolve(path, locale);
		if (redirect !== null) {
			const target = isExternal(redirect.target) ? redirect.target : await site.url(redirect.target, locale);
			return { type: "redirect", status: redirect.status, location: withQuery(target, url.search) };
		}
		return { type: "notFound", status: 404, locale, path, cms: asJson(await sharedProps(site, locale, null)) };
	}
	const props = await pageProps(site, page, locale, path);
	return { type: "page", status: 200, headers: props.seo.noindex ? { "X-Robots-Tag": "noindex" } : {}, props: asJson(props) };
}

/** PageResolver: the page at a path in a locale, live only unless previewing. */
export async function resolvePage(site: Site, path: string, locale: string, preview = false): Promise<Page | null> {
	const id = await site.pages.slugs.pageAt(path.replace(/^\/+|\/+$/g, ""), locale);
	return id === null ? null : site.pages.find(id, { live: !preview });
}

export async function pageProps(site: Site, page: Page, locale: string, path: string): Promise<PageShape> {
	const type = site.pageTypes.has(page.type) ? site.pageTypes.get(page.type) : null;
	const content = page.content ?? type?.template(page) ?? [];
	const { blocks, fields } = await resolveBlocks(site, content, locale, page);
	const resolved = validateProps(site, bindForms(site, blocks, page, locale));
	const isPreview = !isLive(page);
	const ctx = await schemaContext(site, page, locale);
	const blockNodes = resolved
		.map((block) => site.blocks.get(block.kind).toSchema(block.props, ctx))
		.filter((node): node is Record<string, unknown> => node !== null);
	const seo = await resolveSeo(site, page, locale, await ancestorCrumbs(site, page, locale), ctx, blockNodes);
	if (isPreview) {
		seo.noindex = true;
	}
	const fallback = await site.languages.defaultLocale();
	return {
		page: {
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
			fields,
		},
		render: assembleSpec(resolved, site.config.rootKind),
		seo,
		breadcrumbs: await breadcrumbs(site, page, locale),
		path,
		isPreview,
		cms: await sharedProps(site, locale, page),
	};
}

export async function sharedProps(site: Site, locale: string, page: Page | null): Promise<SharedShape> {
	const languages = await site.languages.frontendActive();
	return {
		settings: await site.settings.forFrontend(),
		locale,
		defaultLocale: await site.languages.defaultLocale(),
		languages: languages.map((row) => ({ locale: row.locale, name: row.name, is_default: row.is_default })),
		layout: await layout(site, locale, page ?? (await site.pages.home())),
		debug: site.config.debug ?? false,
		strings: { previewBanner: await site.t("pages.preview.banner", locale) },
	};
}

async function layout(site: Site, locale: string, page: Page | null): Promise<Record<Slot, ResolvedBlock[]>> {
	const content = await site.layouts.content();
	const out = { header: [], footer: [] } as Record<Slot, ResolvedBlock[]>;
	for (const slot of SLOTS) {
		const { blocks } = await resolveBlocks(site, content[slot], locale, null);
		out[slot] = validateProps(site, page === null ? blocks : bindForms(site, blocks, page, locale));
	}
	return out;
}

/**
 * Every `unknown` in the props is already JSON: it comes from a JSON column,
 * a zod-checked block prop or a JSON-LD node. The type says so for the host.
 */
const asJson = <T>(value: T): Jsonify<T> => value as Jsonify<T>;

function withQuery(url: string, search: string): string {
	if (search === "" || search === "?" || url.includes("?")) {
		return url;
	}
	const hash = url.indexOf("#");
	return hash === -1 ? `${url}${search}` : `${url.slice(0, hash)}${search}${url.slice(hash)}`;
}
