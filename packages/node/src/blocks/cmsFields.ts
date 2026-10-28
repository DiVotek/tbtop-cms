import { currentContext, defaultLocale, Field, S, Select, walk, type WithValue } from "@tbtop/admin";
import { quickCreatePage } from "../content/pageQuickCreate";
import { LINK_TYPES, type LinkType } from "../content/link";
import { acceptsType, pageId, pageNameOf, searchPages } from "../content/pageRefs";
import type { Site } from "../site";
import { CollectionField } from "./collectionField";

/** The stored value of a link field: a target, never a resolved href, so a page link survives a slug change. */
export type StoredLink = {
	type: LinkType;
	page?: string | null;
	value?: string | null;
	anchor?: string | null;
	label?: Record<string, string | null> | string | null;
};

/** What the CMS editors hand a link, pages or collection field: its lookups and the admin's search URLs. */
export type CmsFieldBinding = { site: Site; searchEndpoint: string; entrySearchEndpoint: (collection: string) => string };

export const LINK_SHAPE_RULE = "link_shape";
export const PAGE_REF_RULE = "page_ref";

/** Wire copy for the link field's client (lang/en/link.php), sent in its options as PHP sends it. */
const LINK_STRINGS = {
	labels: { page: "Page", external: "URL", email: "Email", phone: "Phone" },
	placeholders: { external: "https://example.com", email: "name@example.com", phone: "+380 44 123 45 67" },
	errors: {
		external: "Enter an http(s) URL, or a viber:/tg:/whatsapp:/skype:/sms: link.",
		email: "Enter a valid email address.",
		phone: "Enter 6 to 20 digits, optionally starting with +.",
		anchor: "Use letters, numbers, dashes and underscores only — no #.",
		label: "Keep the link text under 255 characters.",
		page: "Pick a page.",
	},
	anchorLabel: "Anchor",
	anchorPlaceholder: "section-id",
	pagePlaceholder: "Search pages…",
	pageSearchLabel: "Search pages",
	pageEmpty: "No pages found.",
	pageFailed: "Could not load pages.",
	pageRetry: "Retry",
	textLabel: "Text",
};

/** `nullable` first unless a required* rule is declared, which a nullable ahead of it would void. */
const withNullable = (declared: string[]): string[] => (declared.some((rule) => rule.startsWith("required")) ? declared : ["nullable", ...declared]);

/**
 * A `link` field (Link.php): stores `{type, page, value, anchor, label}`. The
 * site gets `{href, type, label}` or null (FormResolver). Its client is the
 * `link` field kind of the CMS admin bundle.
 */
export class LinkField<N extends string = string> extends Field<N, StoredLink | null> {
	readonly kind = "link";

	/** Restricts the type switch, client and server alike. */
	types(types: LinkType[]): this {
		const unknown = types.filter((type) => !LINK_TYPES.includes(type));
		if (unknown.length > 0) {
			throw new Error(`"${this.name}": unknown link type(s) ${unknown.join(", ")}.`);
		}
		return this.set("types", [...types]);
	}

	/** One target for every locale: the resolver localizes a page's path at render time. */
	override translatable(value = true): WithValue<this, Record<string, StoredLink | null>> {
		if (value) {
			throw new Error(`"${this.name}": a link field is not translatable — it stores one target for every locale.`);
		}
		return super.translatable(false);
	}

	/** Derived on read, so the editor's required gate (PrefixedField) can rewrite the declared rules. */
	override ruleEntries(): string[] {
		const declared = super.ruleEntries().filter((rule) => rule !== "nullable" && rule !== "array" && !rule.startsWith(LINK_SHAPE_RULE));
		const types = (this.optionValue("types") as string[] | undefined) ?? [];
		return [...withNullable(declared), "array", types.length === 0 ? LINK_SHAPE_RULE : `${LINK_SHAPE_RULE}:${types.join(",")}`];
	}

	bindCms(binding: CmsFieldBinding): this {
		return this.set("searchEndpoint", binding.searchEndpoint);
	}

	/** The editor locales (default first, then the admin-active ones) and the client copy, read per request. */
	override toNode() {
		const context = currentContext();
		const fallback = defaultLocale(context);
		this.set("locales", [...new Set([fallback, ...context.contentLocales])]);
		this.set("defaultLocale", fallback);
		for (const [key, value] of Object.entries(LINK_STRINGS)) {
			this.set(key, value);
		}
		return super.toNode();
	}
}

const SEARCH_LIMIT = 20;

/**
 * A `pages` field (Pages.php): a searchable multiple select of pages that
 * stores their ids as strings, in the order picked. On the wire it is a plain
 * async select. The site gets the live ones as `{id, name, url}[]`.
 */
export class PagesField<N extends string = string> extends Select<N, string[]> {
	private exceptId: number | null = null;
	private site: Site | null = null;

	constructor(name: N) {
		super(name);
		this.multiple().searchable();
	}

	/** Accept pages of this type (and subtype); call again for more pairs. Never called: any page. */
	of(type: string, subtype?: string): this {
		if (type === "" || /[/,]/.test(type) || (subtype !== undefined && (subtype === "" || /[/,]/.test(subtype)))) {
			throw new Error(`Invalid page type key "${subtype === undefined ? type : `${type}/${subtype}`}".`);
		}
		const pair = subtype === undefined ? type : `${type}/${subtype}`;
		const pairs = this.typePairs();
		return this.set("pageTypes", pairs.includes(pair) ? pairs : [...pairs, pair]);
	}

	max(count: number): this {
		if (!Number.isInteger(count) || count < 1) {
			throw new Error(`"${this.name}": max() needs at least 1.`);
		}
		return this.rules(`max:${count}`);
	}

	/**
	 * Lets the editor add a page by typing its name: published, of the one
	 * `of()` type, under the parent of the newest page of that type (a tag
	 * next to the other tags). The first page of the type is made in Pages.
	 * With `fields` and `using`, the plain Select creatable().
	 */
	override creatable(fields: Field[] = [], using?: (validated: Record<string, unknown>) => unknown): this {
		if (using !== undefined) {
			return super.creatable(fields, using);
		}
		return super.creatable([new S().text("name").label("Name").required().rules("max:255")], (validated) => {
			if (this.site === null) {
				throw new Error(`"${this.name}": creatable() runs only on a form the CMS admin built.`);
			}
			const { locale, user } = currentContext();
			const id = (user as { id?: unknown } | null)?.id;
			return quickCreatePage(this.site, this.typePairs(), { name: String(validated.name ?? ""), locale, by: typeof id === "number" || typeof id === "string" ? id : null });
		});
	}

	/** Leaves this page out of the search and rejects it on save: a page does not reference itself. */
	exceptPage(id: number): this {
		this.exceptId = id;
		return this;
	}

	/** One id list for every locale. */
	override translatable(value = true): WithValue<this, Record<string, string[] | null>> {
		if (value) {
			throw new Error(`"${this.name}": a pages field is not translatable.`);
		}
		return super.translatable(false);
	}

	override ruleEntries(): string[] {
		const declared = super.ruleEntries().filter((rule) => rule !== "nullable" && rule !== "array" && !rule.startsWith(PAGE_REF_RULE));
		return [...withNullable(declared), this.exceptId === null ? PAGE_REF_RULE : `${PAGE_REF_RULE}:except=${this.exceptId}`];
	}

	typePairs(): string[] {
		return (this.optionValue("pageTypes") as string[] | undefined) ?? [];
	}

	/** The search and label lookup over the site's pages, drafts included: an editor may reference a page before publishing it. */
	bindCms(binding: CmsFieldBinding): this {
		const { site } = binding;
		this.site = site;
		const accepts = (page: { id: number; type: string; subtype: string | null }) =>
			page.id !== this.exceptId && acceptsType(this.typePairs(), page.type, page.subtype);
		const label = async (page: { id: number; name: Record<string, unknown> }) =>
			pageNameOf(page, currentContext().locale, await site.languages.defaultLocale()) ?? `#${page.id}`;
		this.query(async (_deps, search) => {
			const context = currentContext();
			const pages = await searchPages(site, { term: search, locales: [context.locale, defaultLocale(context)], limit: SEARCH_LIMIT, accepts });
			return Promise.all(pages.map(async (page) => ({ value: String(page.id), label: await label(page) })));
		});
		this.resolveUsing(async (value) => {
			const id = pageId(value);
			const page = id === null ? null : await site.pages.find(id);
			return page === null ? null : label(page);
		});
		return this;
	}
}

/** `linkField("cta")` in a block's form(): a page, URL, email or phone link with its text. */
export function linkField<const N extends string>(name: N): LinkField<N> {
	return new LinkField(name);
}

/** `pagesField("related").of("article")` in a block's form(): a searchable multi-pick of pages. */
export function pagesField<const N extends string>(name: N): PagesField<N> {
	return new PagesField(name);
}

/** Hands every link, pages and collection field in a form (repeaters included) the CMS lookups. */
export function bindCmsFields<T extends unknown[]>(fields: T, binding: CmsFieldBinding): T {
	for (const field of walk.collectAll(fields, (value) => value instanceof LinkField || value instanceof PagesField)) {
		(field as LinkField | PagesField).bindCms(binding);
	}
	const { site, entrySearchEndpoint } = binding;
	for (const field of walk.collectAll(fields, (value) => value instanceof CollectionField)) {
		(field as CollectionField).bindCms({
			collection: (key) => (site.collections.has(key) ? site.collections.get(key) : null),
			searchEndpoint: entrySearchEndpoint,
		});
	}
	return fields;
}
