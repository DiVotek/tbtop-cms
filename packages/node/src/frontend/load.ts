import { assembleSpec, bindForms, type RenderSpec, type ResolvedBlock, resolveBlocks, validateProps } from "../blocks/resolver";
import { SLOTS, type Slot } from "../content/layouts";
import { isLive, type Page } from "../content/page";
import type { PageTypeDef, PageTypeUnion } from "../content/pageTypes";
import { isExternal } from "../content/redirects";
import { resolveSeo, type Seo } from "../seo/resolver";
import { schemaContext } from "../seo/schemaContext";
import type { Site } from "../site";
import type { Jsonify } from "../support/values";
import { ancestorCrumbs, breadcrumbs, type Crumb } from "./breadcrumbs";
import { type PageColumns, pageColumns } from "./pageView";
import { type ErrorPageProps, errorPageProps } from "./errorPage";

/** The shared `cms` prop (ShareCmsProps): site settings, locales, the resolved header and footer. */
export type SharedProps = Jsonify<SharedShape>;

/**
 * The props of a CMS page, all JSON: a host passes them through its loader as
 * they are. `page` is a union by `page.type` over the built-in types and `T`
 * (createCms infers it from `pageTypes`), so `page.type === "recipe"`
 * narrows `page.fields` to that type's schema.
 */
export type PageProps<T extends readonly PageTypeDef[] = readonly PageTypeDef[]> = Jsonify<PageShape<PageTypeUnion<T>>>;

export type SharedShape = {
	settings: Record<string, unknown>;
	locale: string;
	defaultLocale: string;
	languages: { locale: string; name: string; is_default: boolean }[];
	layout: Record<Slot, ResolvedBlock[]>;
	debug: boolean;
	strings: { previewBanner: string };
};

type PageShape<P = { type: string; fields: Record<string, unknown> }> = {
	page: Omit<PageColumns, "type"> & P;
	render: RenderSpec;
	seo: Seo;
	breadcrumbs: Crumb[];
	path: string;
	isPreview: boolean;
	cms: SharedShape;
};


export type LoadResult<P = PageProps> =
	| { type: "page"; status: 200; headers: Record<string, string>; props: P }
	| { type: "redirect"; status: number; location: string }
	| { type: "notFound"; status: 404; locale: string; path: string; cms: SharedProps; props: ErrorPageProps };

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
 * A Location must be a ByteString, and a browser drops tabs and newlines from
 * a URL, so everything outside printable ASCII is percent-encoded, as are `\`
 * (folded into `/` by browsers). Escapes already present are kept.
 */
function headerSafe(location: string): string {
	return location.replace(/[^\x21-\x7e]|\\/gu, (char) => {
		try {
			return encodeURIComponent(char);
		} catch {
			return "%EF%BF%BD";
		}
	});
}

/** An external target: non-ASCII hosts go to punycode and the rest is percent-encoded. */
function externalLocation(target: string, origin: string): string {
	if (/^[\x21-\x7e]*$/.test(target)) {
		return target;
	}
	try {
		return new URL(target, origin).href;
	} catch {
		return headerSafe(target);
	}
}

/**
 * SetLanguage: a default-locale prefix 301s to the bare path; a frontend
 * locale prefix is stripped and picked; anything else is the default locale.
 * Empty segments stay in the routed path (`blog//post` misses, as in PHP).
 */
export async function routeLocale(
	site: Site,
	url: URL,
): Promise<{ type: "redirect"; location: string } | { type: "route"; locale: string; path: string }> {
	const path = decodePath(url.pathname).replace(/^\/+|\/+$/g, "");
	const segments = path === "" ? [] : path.split("/");
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
export async function loadPage(
	site: Site,
	request: Request,
	options: { preview: boolean; waitUntil?: (task: Promise<unknown>) => void },
): Promise<LoadResult> {
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
			const target = isExternal(redirect.target)
				? externalLocation(redirect.target, site.origin)
				: headerSafe(await site.url(redirect.target, locale));
			return { type: "redirect", status: redirect.status, location: withQuery(target, url.search) };
		}
		await logMiss(site, request, { path, locale }, options.waitUntil);
		const props = await errorPageProps(site, 404, locale, path);
		return { type: "notFound", status: 404, locale, path, cms: props.cms, props };
	}
	const props = await pageProps(site, page, locale, path);
	return { type: "page", status: 200, headers: props.seo.noindex ? { "X-Robots-Tag": "noindex" } : {}, props: asJson(props) };
}

/** PageResolver: the page at a path in a locale, live only unless previewing. */
export async function resolvePage(site: Site, path: string, locale: string, preview = false): Promise<Page | null> {
	return site.pages.findAt(path.replace(/^\/+|\/+$/g, ""), locale, { live: !preview });
}

export async function pageProps(site: Site, page: Page, locale: string, path: string): Promise<PageShape> {
	const content = page.content ?? site.pageTypes.templateFor(page) ?? [];
	// Independent reads start together: on D1 each query is a round trip, and only a chain of them adds up.
	const [{ blocks, fields }, ctx, parents, trail, cms, fallback] = await Promise.all([
		resolveBlocks(site, content, locale, page),
		schemaContext(site, page, locale),
		ancestorCrumbs(site, page, locale),
		breadcrumbs(site, page, locale),
		sharedProps(site, locale, page),
		site.languages.defaultLocale(),
	]);
	const resolved = validateProps(site, bindForms(site, blocks, page, locale));
	const isPreview = !isLive(page);
	const blockNodes = resolved
		.map((block) => site.blocks.get(block.kind).toSchema(block.props, ctx))
		.filter((node): node is Record<string, unknown> => node !== null)
		// Each node is a JSON-LD document of its own: one without @context is not schema.org to a crawler.
		.map((node) => ("@context" in node ? node : { "@context": "https://schema.org", ...node }));
	const seo = await resolveSeo(site, page, locale, parents, ctx, blockNodes);
	if (isPreview) {
		seo.noindex = true;
	}
	return {
		page: { ...pageColumns(site, page, locale, fallback), fields },
		render: assembleSpec(resolved, site.config.rootKind),
		seo,
		breadcrumbs: trail,
		path,
		isPreview,
		cms,
	};
}

export async function sharedProps(site: Site, locale: string, page: Page | null): Promise<SharedShape> {
	const [languages, settings, defaultLocale, slots, previewBanner] = await Promise.all([
		site.languages.frontendActive(),
		site.settings.forFrontend(),
		site.languages.defaultLocale(),
		(async () => layout(site, locale, page ?? (await site.pages.home())))(),
		site.t("pages.preview.banner", locale),
	]);
	return {
		settings,
		locale,
		defaultLocale,
		languages: languages.map((row) => ({ locale: row.locale, name: row.name, is_default: row.is_default })),
		layout: slots,
		debug: site.config.debug ?? false,
		strings: { previewBanner },
	};
}

async function layout(site: Site, locale: string, page: Page | null): Promise<Record<Slot, ResolvedBlock[]>> {
	const content = await site.layouts.content();
	const out = { header: [], footer: [] } as Record<Slot, ResolvedBlock[]>;
	const resolved = await Promise.all(SLOTS.map((slot) => resolveBlocks(site, content[slot], locale, null)));
	SLOTS.forEach((slot, index) => {
		const blocks = resolved[index]?.blocks ?? [];
		out[slot] = validateProps(site, page === null ? blocks : bindForms(site, blocks, page, locale));
	});
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

/** PageNotFound → RecordsNotFoundHit: a failed write never turns the 404 into an error. */
async function logMiss(site: Site, request: Request, at: { path: string; locale: string }, waitUntil?: (task: Promise<unknown>) => void): Promise<void> {
	const task = site.notFound
		.record({ ...at, referer: request.headers.get("referer"), userAgent: request.headers.get("user-agent") })
		.catch((error: unknown) => console.warn("not_found.record_failed", { path: at.path, error: String(error) }));
	if (waitUntil === undefined) {
		await task;
	} else {
		waitUntil(task);
	}
}
