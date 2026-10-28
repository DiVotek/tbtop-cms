import type { PageRefWithFields, ResolveContext } from "./blocks/context";
import { resolvePageFields } from "./blocks/resolver";
import { type PageView, pageColumns, pageViews } from "./frontend/pageView";
import type { BlockRegistry } from "./blocks/registry";
import { type CmsConfig, defaultMedia, type MediaDetails, type MediaImage } from "./config";
import { AuditLog } from "./content/audit";
import { Languages } from "./content/languages";
import { Layouts } from "./content/layouts";
import { NotFoundLog } from "./content/notFoundLog";
import { isWellFormed, linkLabel, parseLink, type ResolvedLink, telNumber } from "./content/link";
import { firstText, type Page, pageFromRow, translationText } from "./content/page";
import { Pages, whereLive } from "./content/pages";
import { childrenLinksPaged, livePageRefs, type PagedOptions, type PageRef, referencedBy, referencedByLinks, referencedByLinksPaged, resolvePageRefs } from "./content/pageRefs";
import { type PagedRefs, Paging } from "./content/pagedPages";
import type { PageTypes } from "./content/pageTypes";
import { Redirects } from "./content/redirects";
import { PageRevisions } from "./content/revisions";
import { richtextHtml } from "./content/richtext";
import { Settings } from "./content/settings";
import { SharedBlocks } from "./content/sharedBlocks";
import { type CmsSettings, settingsObject } from "./content/siteSettings";
import { namedFormProps } from "./forms/locate";
import { Submissions } from "./forms/submissions";
import { Notifications } from "./notifications";
import type { SchemaTypes } from "./seo/schemaTypes";
import { ContentCache } from "./support/cache";
import type { Collections } from "./content/collections";
import { Entries, type Entry } from "./content/entries";
import { SiteContacts } from "./content/siteContacts";
import { EntryList, type ResolvedEntry } from "./content/entryList";
import { flattenLocales, translatablePaths } from "./blocks/locale";
import { resolveForm } from "./blocks/fields";
import { S } from "@tbtop/admin";
import { translate } from "./support/strings";

/**
 * One request's view of the CMS: the stores, each read once and memoized,
 * and the URL helpers. createCms builds a fresh Site per request, so no
 * stale state survives between requests.
 */
/** The srcset steps `imageLoader` is asked for (next/image's common device widths). */
const IMAGE_WIDTHS = [640, 960, 1280, 1920];

const positive = (value: number | null | undefined): number | null => (typeof value === "number" && Number.isFinite(value) && value > 0 ? Math.round(value) : null);

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
	readonly notFound: NotFoundLog;
	/** Collection entries, drafts included (the admin's store). */
	readonly entries: Entries;
	/** Phones, emails and social links, from the built-in collections. */
	readonly contacts: SiteContacts;
	/** Published collections by `{key}:{locale}`, resolved once per request. */
	private readonly collectionLists = new Map<string, Promise<EntryList>>();
	/** Every published entry by collection, in one query for all the collections a request reads. */
	private publishedEntries: Promise<Map<string, Entry[]>> | null = null;
	private readonly cache: { store: ContentCache; read: boolean } | null;
	/** Admin writes by a signed-in user (`activity_log`). */
	readonly audit: AuditLog;
	/** The request's `?page=` and the paged archive its page listed (`referencedByLinksPaged`, `childrenLinksPaged`). */
	readonly paging = new Paging();
	/** The settings object by locale, once per request. */
	private readonly settingsByLocale = new Map<string, Promise<CmsSettings>>();
	/** Link targets by locale and page id, on a frontend render (`cached`). */
	private readonly targets: Map<string, Map<number, Promise<LinkTarget>>> | null;

	constructor(
		readonly config: CmsConfig,
		readonly pageTypes: PageTypes,
		readonly schemaTypes: SchemaTypes,
		readonly collections: Collections,
		/** The site origin without a trailing slash: `https://example.com`. */
		readonly origin: string,
		/** Read settings and languages through `config.cache` (frontend requests); writes bump it either way. */
		options: { cached?: boolean } = {},
	) {
		const db = config.db;
		this.audit = new AuditLog(db);
		const cache = config.cache === undefined ? null : { store: new ContentCache(config.cache.store, config.cache.ttl ?? 3600), read: options.cached === true };
		this.cache = cache;
		this.languages = new Languages(db, cache, this.audit);
		this.settings = new Settings(db, {
			cache,
			secretKeys: config.secretSettings ?? [],
			secretKey: config.settingsKey,
		});
		this.pages = new Pages(
			db,
			async () => (await this.languages.frontendActive()).map((row) => row.locale),
			() => this.languages.defaultLocale(),
			pageTypes,
			new PageRevisions(db, config.revisions?.keep ?? 25),
			this.audit,
		);
		this.layouts = new Layouts(db, this.audit);
		this.sharedBlocks = new SharedBlocks(db, this.audit);
		this.redirects = new Redirects(db, config.redirects?.allowedExternalHosts ?? [], this.audit);
		this.blocks = config.blocks;
		this.submissions = new Submissions(db);
		this.notFound = new NotFoundLog(db, this.settings, config.notFound?.ignore);
		this.notifications = new Notifications(this);
		this.contacts = new SiteContacts(this);
		this.entries = new Entries(db, collections, () => this.languages.defaultLocale(), () => this.collectionsChanged(), this.audit);
		// A frontend render reads; each page, slug and link target is loaded once for all its blocks and layouts.
		this.targets = options.cached === true ? new Map() : null;
		if (options.cached === true) {
			this.pages.readOnce();
		}
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

	/**
	 * ResolveContext::mediaImage: the srcset lists `imageLoader` copies at
	 * the standard widths narrower than the original, then the original at
	 * its own width; no loader, no known width or no narrower step: null.
	 */
	mediaImage(ref: string | null | undefined): MediaImage | null {
		const details = this.media(ref);
		if (details === null || ref === null || ref === undefined) {
			return null;
		}
		const width = positive(details.width);
		const loader = this.config.imageLoader;
		const steps = loader === undefined || width === null ? [] : IMAGE_WIDTHS.filter((step) => step < width);
		const srcset = steps.length === 0 || loader === undefined ? null : [...steps.map((step) => `${loader(ref, step)} ${step}w`), `${details.url} ${width}w`].join(", ");
		return { src: details.url, srcset, width, height: positive(details.height), alt: details.alt ?? null };
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

	/**
	 * LinkResolver: a link field value to `{ href, type, label }`, or null when
	 * it points nowhere live. `targets` (linkTargets) answers a page link
	 * without a query; a page it does not hold is looked up.
	 */
	async link(value: unknown, locale: string, targets?: LinkTargets): Promise<ResolvedLink | null> {
		const link = parseLink(value);
		if (link === null || !isWellFormed(link)) {
			return null;
		}
		const fallback = await this.languages.defaultLocale();
		const target = link.type === "page" ? (targets?.get(Number(link.page)) ?? (await this.linkTargets([Number(link.page)], locale)).get(Number(link.page))) : null;
		const page = target?.page ?? null;
		let href: string | null = null;
		if (link.type === "page") {
			const url = target?.url ?? null;
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

	/**
	 * The live pages of `ids` with their URL in `locale`, in two queries: what
	 * a resolve pass loads up front so its page links cost no query each. A
	 * page that is gone or not live maps to `{ page: null, url: null }`.
	 */
	async linkTargets(ids: number[], locale: string): Promise<LinkTargets> {
		const unique = [...new Set(ids.filter((id) => Number.isInteger(id) && id > 0))];
		if (this.targets === null) {
			return this.loadTargets(unique, locale);
		}
		const known = this.targets.get(locale) ?? new Map<number, Promise<LinkTarget>>();
		this.targets.set(locale, known);
		const missing = unique.filter((id) => !known.has(id));
		if (missing.length > 0) {
			const loading = this.loadTargets(missing, locale);
			for (const id of missing) {
				known.set(
					id,
					loading.then((targets) => targets.get(id) ?? { page: null, url: null }),
				);
			}
		}
		return new Map(await Promise.all(unique.map(async (id) => [id, await (known.get(id) as Promise<LinkTarget>)] as const)));
	}

	private async loadTargets(unique: number[], locale: string): Promise<LinkTargets> {
		const targets: LinkTargets = new Map(unique.map((id) => [id, { page: null, url: null }]));
		if (unique.length === 0) {
			return targets;
		}
		const pages = (await whereLive(this.pages.query().selectAll("tbtop_pages").where("tbtop_pages.id", "in", unique)).execute()).map(pageFromRow);
		const paths = await this.pages.slugs.pathsFor(
			pages.map((page) => page.id),
			locale,
		);
		for (const page of pages) {
			const path = paths.get(page.id);
			targets.set(page.id, { page, url: path === undefined ? null : await this.url(path, locale) });
		}
		return targets;
	}

	/** The Site settings as one object in `locale` (`ctx.settings()`, `props.cms.settings`), once per request. */
	settingsIn(locale: string): Promise<CmsSettings> {
		let loading = this.settingsByLocale.get(locale);
		if (loading === undefined) {
			loading = settingsObject(this, locale);
			this.settingsByLocale.set(locale, loading);
			loading.catch(() => this.settingsByLocale.delete(locale));
		}
		return loading;
	}

	async resolveContext(locale: string, page: Page | null, fields: Record<string, unknown> = {}, targets?: LinkTargets, nested = false): Promise<ResolveContext> {
		const fallback = await this.languages.defaultLocale();
		return {
			locale,
			fallbackLocale: fallback,
			page,
			fields,
			mediaUrl: (ref) => this.mediaUrl(ref),
			mediaImage: (ref) => this.mediaImage(ref),
			richtextHtml,
			link: (value) => this.link(value, locale, targets),
			pages: ((value: unknown, options?: { fields?: boolean }) =>
				options?.fields === true ? this.pagesWithFields(value, locale, nested) : resolvePageRefs(this, value, locale)) as ResolveContext["pages"],
			referencedBy: (field, from) => referencedByLinks(this, page, field, { locale, ...(from === undefined ? {} : { from }) }),
			referencedByPaged: (field, options = {}) => referencedByLinksPaged(this, page, field, { ...options, locale }),
			childrenPaged: (options = {}) => childrenLinksPaged(this, page, { ...options, locale }),
			collection: (key) => this.collection(key, locale),
			pageText: (field) => (page === null ? null : translationText(page, field, locale, fallback)),
			t: (key) => translate(key, locale, fallback, this.config.strings),
			settings: () => this.settingsIn(locale),
			site: this,
		};
	}

	/**
	 * Pages the host listed itself, each as `props.page` (columns in `locale`
	 * with the default as fallback, body as HTML, image URL) plus its `url`;
	 * `fields: true` adds the resolved custom fields. Nothing is filtered:
	 * list with whereLive for what the frontend shows.
	 */
	pageViews(pages: Page[], locale: string, options: { fields?: boolean } = {}): Promise<PageView[]> {
		return pageViews(this, pages, locale, options);
	}

	/** ctx.pages(value, { fields: true }): each live ref with its page's resolved fields, one level deep. */
	private async pagesWithFields(value: unknown, locale: string, nested: boolean): Promise<PageRefWithFields[]> {
		const live = await livePageRefs(this, value, locale);
		const fields = nested ? live.map(() => ({})) : await resolvePageFields(this, live.map(({ page }) => page), locale);
		const fallback = await this.languages.defaultLocale();
		return live.map(({ ref, page }, i) => {
			const { id: _id, name: _name, ...columns } = pageColumns(this, page, locale, fallback);
			return { ...ref, ...columns, fields: fields[i] ?? {} };
		});
	}

	/**
	 * Cms::referencedBy: the live pages whose page-type pages field `field`
	 * picks `page`, optionally only of type or subtype `from`; by sort order,
	 * then name. Empty for a null page.
	 */
	referencedBy(page: Page | null, field: string, options: { from?: string; locale?: string } = {}): Promise<Page[]> {
		return this.withLocale(options, (locale) => referencedBy(this, page, field, { ...options, locale }));
	}

	/** Cms::referencedByLinks: referencedBy as `{id, name, url}` links in the locale. */
	referencedByLinks(page: Page | null, field: string, options: { from?: string; locale?: string } = {}): Promise<PageRef[]> {
		return this.withLocale(options, (locale) => referencedByLinks(this, page, field, { ...options, locale }));
	}

	/**
	 * Cms::referencedByLinksPaged: one page of referencedByLinks for an
	 * archive (a tag's articles), newest first by published_at. `page` is the
	 * request's `?page=` unless given; on a frontend render page 2 on gets a
	 * `?page=N` canonical and a page past the end is a 404.
	 */
	referencedByLinksPaged(page: Page | null, field: string, options: Partial<PagedOptions> = {}): Promise<PagedRefs<PageRef>> {
		return this.withLocale(options, (locale) => referencedByLinksPaged(this, page, field, { ...options, locale }));
	}

	/** Cms::childrenLinksPaged: one page of a page's live children (of type or subtype `from` when given), the same way. */
	childrenLinksPaged(page: Page | null, options: Partial<PagedOptions> = {}): Promise<PagedRefs<PageRef>> {
		return this.withLocale(options, (locale) => childrenLinksPaged(this, page, { ...options, locale }));
	}

	private async withLocale<R>(options: { locale?: string }, run: (locale: string) => Promise<R>): Promise<R> {
		return run(options.locale ?? (await this.languages.defaultLocale()));
	}

	/**
	 * Cms::collection: a collection's published entries resolved for the
	 * locale (default language unless given), in the editor's order. The
	 * whole collection is resolved once and kept under the content cache on
	 * a frontend request; filter the list in memory.
	 */
	collection(key: string, locale?: string): Promise<EntryList> {
		return this.withLocale(locale === undefined ? {} : { locale }, (resolved) => {
			const memo = `${key}:${resolved}`;
			let list = this.collectionLists.get(memo);
			if (list === undefined) {
				const collection = this.collections.get(key);
				const compute = () => this.resolveEntries(key, resolved);
				list = (this.cache?.read === true ? this.cache.store.remember(`collection:${memo}`, compute) : compute()).then((entries) => new EntryList(entries, collection.relatedField));
				this.collectionLists.set(memo, list);
				list.catch(() => this.collectionLists.delete(memo));
			}
			return list;
		});
	}

	/** Each published entry flattened to the locale and resolved by the collection; one that throws is skipped with a warning. */
	private async resolveEntries(key: string, locale: string): Promise<ResolvedEntry[]> {
		const collection = this.collections.get(key);
		const locales = (await this.languages.all()).map((row) => row.locale);
		const ctx = await this.resolveContext(locale, null);
		const form = collection.form(new S());
		const paths = translatablePaths(form);
		const out: ResolvedEntry[] = [];
		for (const entry of (await this.published()).get(key) ?? []) {
			try {
				const input = flattenLocales(entry.data, locale, ctx.fallbackLocale, locales, paths);
				const fields = collection.resolve === null ? await resolveForm(form, input, ctx) : await collection.resolve(input, ctx);
				out.push({ ...fields, id: entry.id });
			} catch (error) {
				if (this.config.strictBlocks === true) {
					throw error;
				}
				console.warn("collection.entry_resolve_failed", { collection: key, entry: entry.id, error: String(error) });
			}
		}
		return out;
	}

	private published(): Promise<Map<string, Entry[]>> {
		if (this.publishedEntries === null) {
			const loading = this.entries.published();
			this.publishedEntries = loading;
			loading.catch(() => {
				if (this.publishedEntries === loading) {
					this.publishedEntries = null;
				}
			});
		}
		return this.publishedEntries;
	}

	/** An entry write: this request's lists and every cached one are stale. */
	private async collectionsChanged(): Promise<void> {
		this.collectionLists.clear();
		this.publishedEntries = null;
		await this.cache?.store.bump();
	}

	/** Forms::props: `action` (and `honeypotField`) for a named form (`formName`) posted from `page`; the default locale unless given. */
	formProps(name: string, page: Page | number, locale?: string): Promise<{ action: string; honeypotField?: string }> {
		return namedFormProps(this, name, page, locale);
	}

	async t(key: string, locale: string, params: Record<string, string | number> = {}): Promise<string> {
		return translate(key, locale, await this.languages.defaultLocale(), this.config.strings, params);
	}
}

type LinkTarget = { page: Page | null; url: string | null };

/** Page link targets by page id (Site.linkTargets). */
export type LinkTargets = Map<number, LinkTarget>;

function pageName(page: Page, locale: string, fallback: string): string | null {
	const name = firstText(page, ["name"], locale, fallback);
	if (name !== "") {
		return name;
	}
	// TranslatableValue::pick: the first non-blank name in any locale.
	return Object.values(page.name).find((value): value is string => typeof value === "string" && value.trim() !== "") ?? null;
}
