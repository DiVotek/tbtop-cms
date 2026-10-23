import type { Cms } from "../cms";
import { type RenderSpec, assembleSpec, bindForms, resolveBlocks, validateProps } from "../blocks/resolver";
import { type Page, pageFromRow, translation, translationText } from "../content/page";
import { whereLive } from "../content/pages";
import { richtextHtml } from "../content/richtext";
import { resolveSeo, type Seo } from "../seo/resolver";
import { schemaContext } from "../seo/schemaContext";
import type { Site } from "../site";
import { translate } from "../support/strings";
import type { Jsonify } from "../support/values";
import type { Crumb } from "./breadcrumbs";
import { routeLocale, type SharedShape, sharedProps } from "./load";

/** ErrorClass: one editable error page per status class, not per status code. */
export type ErrorClass = "4xx" | "5xx";

export function errorClassFor(status: number): ErrorClass | null {
	const hundreds = Math.trunc(status / 100);
	return hundreds === 4 ? "4xx" : hundreds === 5 ? "5xx" : null;
}

/**
 * The `seo` of an error page: always noindex, with no canonical, og:url or
 * JSON-LD. An error page has no slug, so a URL would point at a made-up path,
 * and it is not a node of the site.
 */
export type ErrorSeo = Omit<Seo, "canonical" | "ogUrl"> & { canonical: null; ogUrl: null };

/**
 * The props of a 404 or 5xx answer (ErrorResponse): the live error page the
 * editor keeps for the status class, or a fallback with no blocks and the
 * package's heading. A type of its own, so `PageProps` stays non-null.
 */
export type ErrorPageProps = Jsonify<ErrorPageShape>;

type ErrorPageShape = {
	status: number;
	page: {
		/** Null in the fallback (no live error page for the class). */
		id: number | null;
		type: string;
		subtype: string | null;
		status: string | null;
		name: string | null;
		title: string | null;
		heading: string | null;
		summary: string | null;
		body: string;
		image: null;
		fields: Record<string, unknown>;
	};
	render: RenderSpec;
	seo: ErrorSeo;
	/** Always empty: an error page is not in the site tree. */
	breadcrumbs: Crumb[];
	locale: string;
	/** The requested path, without the locale prefix. */
	path: string;
	isPreview: false;
	cms: SharedShape;
};

/** ErrorPageResolver: the live page for the status class, by its class column, never by path. */
export async function errorPageFor(site: Site, status: number): Promise<Page | null> {
	const errorClass = errorClassFor(status);
	if (errorClass === null) {
		return null;
	}
	const row = await whereLive(site.pages.query().selectAll("tbtop_pages").where("error_class", "=", errorClass)).executeTakeFirst();
	return row === undefined ? null : pageFromRow(row);
}

/** ErrorResponse's props: the error page for the status, or FallbackProps. */
export async function errorPageProps(site: Site, status: number, locale: string, path: string): Promise<ErrorPageProps> {
	const page = await errorPageFor(site, status);
	const cms = await sharedProps(site, locale, null);
	const shape = page === null ? await fallbackShape(site, status, locale) : await pageShape(site, page, locale);
	return { ...shape, status, locale, path, isPreview: false, breadcrumbs: [], cms } as ErrorPageProps;
}

/**
 * For a host's 5xx handler (`cms.errorPage`), which must not throw: when the
 * database is what failed, a 5xx falls back to props built without it (no
 * settings, languages or layout), as PHP falls through to its static view.
 */
export async function errorPage(site: Site, request: Request, status: number): Promise<ErrorPageProps> {
	try {
		const route = await routeLocale(site, new URL(request.url));
		const locale = route.type === "route" ? route.locale : await site.languages.defaultLocale();
		const path = route.type === "route" ? route.path : route.location.replace(/^\/+|[?#].*$/g, "");
		return await errorPageProps(site, status, locale, path);
	} catch (error) {
		if (errorClassFor(status) !== "5xx") {
			throw error;
		}
		console.error("cms.error_page_failed", { status, error });
		return staticProps(site, status, new URL(request.url).pathname.replace(/^\/+|\/+$/g, ""));
	}
}

type Content = Pick<ErrorPageShape, "page" | "render" | "seo">;

async function pageShape(site: Site, page: Page, locale: string): Promise<Content> {
	const { blocks, fields } = await resolveBlocks(site, page.content ?? [], locale, page);
	const resolved = validateProps(site, bindForms(site, blocks, page, locale));
	const seo = await resolveSeo(site, page, locale, [], await schemaContext(site, page, locale));
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
			image: null,
			fields,
		},
		render: assembleSpec(resolved, site.config.rootKind),
		seo: { ...seo, canonical: null, ogUrl: null, noindex: true, jsonLd: [] },
	};
}

/** FallbackProps: no error page to render, so an empty spec and the package's heading. */
async function fallbackShape(site: Site, status: number, locale: string): Promise<Content> {
	const choice = await site.settings.string("seo.twitter_card", "auto");
	return fallbackContent(site, status, locale, await site.languages.defaultLocale(), await site.settings.string("site.name"), choice);
}

function fallbackContent(site: Site, status: number, locale: string, fallback: string, siteName: string, twitterCard: string): Content {
	const heading = translate(`errors.${errorClassFor(status) ?? "4xx"}.heading`, locale, fallback, site.config.strings);
	return {
		page: {
			id: null,
			type: "page",
			subtype: null,
			status: null,
			name: null,
			title: null,
			heading,
			summary: null,
			body: "",
			image: null,
			fields: {},
		},
		render: assembleSpec([], site.config.rootKind),
		seo: {
			title: heading,
			description: "",
			canonical: null,
			ogImage: null,
			ogImageAlt: null,
			ogImageType: null,
			ogImageWidth: null,
			ogImageHeight: null,
			ogTitle: "",
			ogDescription: "",
			noindex: true,
			siteName,
			ogType: "website",
			ogUrl: null,
			ogLocale: null,
			ogLocaleAlternates: [],
			articlePublishedTime: null,
			articleModifiedTime: null,
			articleAuthor: null,
			// SeoResolver's rule with no image: an explicit large card wins, anything else is `summary`.
			twitterCard: twitterCard === "summary_large_image" ? twitterCard : "summary",
			alternates: [],
			xDefault: null,
			ownTitle: "",
			ownDescription: "",
			favicon: null,
			verificationGoogle: null,
			verificationBing: null,
			gtmContainerId: null,
			jsonLd: [],
		},
	};
}

/** The last resort of a 5xx: nothing read from the database. */
function staticProps(site: Site, status: number, path: string): ErrorPageProps {
	const locale = "en";
	const cms: SharedShape = {
		settings: {},
		locale,
		defaultLocale: locale,
		languages: [],
		layout: { header: [], footer: [] },
		debug: site.config.debug ?? false,
		strings: { previewBanner: translate("pages.preview.banner", locale, locale, site.config.strings) },
	};
	const content = fallbackContent(site, status, locale, locale, "", "auto");
	return { ...content, status, locale, path, isPreview: false, breadcrumbs: [], cms } as ErrorPageProps;
}

/**
 * PageSeeder::seedErrorPage for both classes: a published system page per
 * class in the default locale, unless one exists (trashed or not). The editor
 * then edits it in the pages list like any other page. Returns the classes created.
 */
export async function ensureErrorPages(cms: Cms): Promise<ErrorClass[]> {
	const site = cms.site();
	const locale = await site.languages.defaultLocale();
	const created: ErrorClass[] = [];
	for (const errorClass of ["4xx", "5xx"] as const) {
		// error_class is unique, so a trashed row counts too.
		const existing = await cms.config.db.selectFrom("tbtop_pages").select("id").where("error_class", "=", errorClass).executeTakeFirst();
		if (existing !== undefined) {
			continue;
		}
		const heading = await site.t(`errors.${errorClass}.heading`, locale);
		await site.pages.create({
			type: "page",
			errorClass,
			isSystem: true,
			status: "published",
			isIndexable: false,
			sortOrder: 0,
			name: { [locale]: heading },
			title: { [locale]: heading },
			heading: { [locale]: heading },
		});
		created.push(errorClass);
	}
	return created;
}
