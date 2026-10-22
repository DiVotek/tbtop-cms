import type { ResolveContext } from "./blocks/context";
import type { BlockRegistry } from "./blocks/registry";
import { type CmsConfig, defaultMedia, type MediaDetails } from "./config";
import { Languages } from "./content/languages";
import { Layouts } from "./content/layouts";
import { isWellFormed, linkLabel, parseLink, type ResolvedLink, telNumber } from "./content/link";
import { firstText, type Page, translationText } from "./content/page";
import { Pages } from "./content/pages";
import type { PageTypes } from "./content/pageTypes";
import { Redirects } from "./content/redirects";
import { richtextHtml } from "./content/richtext";
import { Settings } from "./content/settings";
import { SharedBlocks } from "./content/sharedBlocks";
import { Submissions } from "./forms/submissions";
import { Notifications } from "./notifications";
import type { SchemaTypes } from "./seo/schemaTypes";
import { translate } from "./support/strings";

/**
 * One request's view of the CMS: the stores, each read once and memoized,
 * and the URL helpers. createCms builds a fresh Site per request, so no
 * stale state survives between requests.
 */
export class Site {
	readonly languages: Languages;
	readonly settings: Settings;
	readonly pages: Pages;
	readonly layouts: Layouts;
	readonly sharedBlocks: SharedBlocks;
	readonly redirects: Redirects;
	readonly blocks: BlockRegistry;
	readonly submissions: Submissions;
	readonly notifications: Notifications;

	constructor(
		readonly config: CmsConfig,
		readonly pageTypes: PageTypes,
		readonly schemaTypes: SchemaTypes,
		/** The site origin without a trailing slash: `https://example.com`. */
		readonly origin: string,
	) {
		const db = config.db;
		this.languages = new Languages(db);
		this.settings = new Settings(db, {
			secretKeys: config.secretSettings ?? [],
			mediaUrl: (ref) => this.mediaUrl(ref),
		});
		this.pages = new Pages(
			db,
			async () => (await this.languages.frontendActive()).map((row) => row.locale),
			() => this.languages.defaultLocale(),
			pageTypes,
		);
		this.layouts = new Layouts(db);
		this.sharedBlocks = new SharedBlocks(db);
		this.redirects = new Redirects(db);
		this.blocks = config.blocks;
		this.submissions = new Submissions(db);
		this.notifications = new Notifications(this);
	}

	media(ref: string | null | undefined): MediaDetails | null {
		if (ref === null || ref === undefined || ref === "") {
			return null;
		}
		return (this.config.media ?? defaultMedia)(ref);
	}

	mediaUrl(ref: string | null | undefined): string | null {
		return this.media(ref)?.url ?? null;
	}

	/** PageUrl::build: `/{path}` for the default locale, `/{locale}/{path}` for the others. */
	async url(path: string, locale: string): Promise<string> {
		const trimmed = path.replace(/^\/+|\/+$/g, "");
		if (locale !== "" && locale !== (await this.languages.defaultLocale())) {
			return `/${locale}${trimmed === "" ? "" : `/${trimmed}`}`;
		}
		return `/${trimmed}`;
	}

	/** url(): an absolute URL on the site origin. */
	absolute(path: string): string {
		if (/^(?:https?:)?\/\//.test(path)) {
			return path;
		}
		const trimmed = path.replace(/^\/+/, "");
		return trimmed === "" ? this.origin : `${this.origin}/${trimmed}`;
	}

	/** The page's root-relative URL in a locale, or null when it has no path there. */
	async pageUrl(page: Page | number, locale: string): Promise<string | null> {
		const path = await this.pages.slugs.pathFor(typeof page === "number" ? page : page.id, locale);
		return path === null ? null : this.url(path, locale);
	}

	/** LinkResolver: a link field value to `{ href, type, label }`, or null when it points nowhere live. */
	async link(value: unknown, locale: string): Promise<ResolvedLink | null> {
		const link = parseLink(value);
		if (link === null || !isWellFormed(link)) {
			return null;
		}
		const fallback = await this.languages.defaultLocale();
		const page = link.type === "page" ? await this.pages.find(Number(link.page), { live: true }) : null;
		let href: string | null = null;
		if (link.type === "page") {
			const url = page === null ? null : await this.pageUrl(page, locale);
			href = url === null ? null : link.anchor === null ? url : `${url}#${link.anchor}`;
		} else if (link.value !== null) {
			href = link.type === "external" ? link.value : link.type === "email" ? `mailto:${link.value}` : `tel:${telNumber(link.value)}`;
		}
		if (href === null) {
			return null;
		}
		const label = linkLabel(link, locale, fallback) ?? (page === null ? null : pageName(page, locale, fallback)) ?? link.value;
		return label === null ? null : { href, type: link.type, label };
	}

	async resolveContext(locale: string, page: Page | null, fields: Record<string, unknown> = {}): Promise<ResolveContext> {
		const fallback = await this.languages.defaultLocale();
		return {
			locale,
			fallbackLocale: fallback,
			page,
			fields,
			mediaUrl: (ref) => this.mediaUrl(ref),
			richtextHtml,
			link: (value) => this.link(value, locale),
			pageText: (field) => (page === null ? null : translationText(page, field, locale, fallback)),
			t: (key) => translate(key, locale, fallback, this.config.strings),
		};
	}

	async t(key: string, locale: string, params: Record<string, string | number> = {}): Promise<string> {
		return translate(key, locale, await this.languages.defaultLocale(), this.config.strings, params);
	}
}

function pageName(page: Page, locale: string, fallback: string): string | null {
	const name = firstText(page, ["name"], locale, fallback);
	return name === "" ? null : name;
}
