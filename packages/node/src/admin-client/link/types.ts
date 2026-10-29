/** Mirrors the PHP stored shape — src/Link/LinkValue.php. `page` is the id as a string. */
export const LINK_TYPES = ["page", "external", "email", "phone"] as const;

export type LinkType = (typeof LINK_TYPES)[number];

/**
 * `label` is a per-locale map while the editor owns it. LocaleFlattener
 * collapses it to one string before render, so readers accept both.
 */
export type LinkLabel = Record<string, string | null>;

export interface LinkValue {
	type: LinkType;
	page: string | null;
	value: string | null;
	anchor: string | null;
	label: LinkLabel | null;
}

export interface LinkOptions {
	/** Rendered by the PHP field from the admin prefix — Link::toNode(). */
	searchEndpoint?: string;
	/** Content locales in order, and the fallback — also from Link::toNode(). */
	locales?: string[];
	defaultLocale?: string;
	types?: LinkType[];
	labels?: Partial<Record<LinkType, string>>;
	placeholders?: Partial<Record<Exclude<LinkType, "page">, string>>;
	errors?: Partial<Record<LinkType | "anchor" | "label", string>>;
	anchorLabel?: string;
	anchorPlaceholder?: string;
	pagePlaceholder?: string;
	/** Accessible name of the page picker's search input. */
	pageSearchLabel?: string;
	/** Shown in the page picker when the search finds nothing. */
	pageEmpty?: string;
	/** Shown in the page picker when the search request fails, next to a retry button. */
	pageFailed?: string;
	pageRetry?: string;
	textLabel?: string;
}

export function isLinkType(raw: unknown): raw is LinkType {
	return typeof raw === "string" && (LINK_TYPES as readonly string[]).includes(raw);
}

/** Untrusted form data: an older save, a hand-edited payload, or a fresh null. */
export function parseLinkValue(raw: unknown): LinkValue | null {
	if (raw === null || typeof raw !== "object") {
		return null;
	}
	const bag = raw as Record<string, unknown>;
	if (!isLinkType(bag.type)) {
		return null;
	}
	return {
		type: bag.type,
		page: str(bag.page),
		value: str(bag.value),
		anchor: str(bag.anchor),
		label: label(bag.label),
	};
}

/** A flattened string arrives keyed under `*` so one shape reaches the editor. */
function label(raw: unknown): LinkLabel | null {
	if (typeof raw === "string") {
		return raw === "" ? null : { "*": raw };
	}
	if (raw === null || typeof raw !== "object" || Array.isArray(raw)) {
		return null;
	}
	const out: LinkLabel = {};
	for (const [locale, text] of Object.entries(raw as Record<string, unknown>)) {
		const one = str(text);
		if (one !== null) {
			out[locale] = one;
		}
	}
	return Object.keys(out).length === 0 ? null : out;
}

/** Mirrors LinkValue::label() in PHP: the locale, then the fallback, then any entry. */
export function pickLabel(link: LinkValue, locale?: string, fallback?: string): string | null {
	if (link.label === null) {
		return null;
	}
	for (const key of [locale, fallback, "*"]) {
		const text = key === undefined ? null : (link.label[key] ?? null);
		if (text !== null) {
			return text;
		}
	}
	return Object.values(link.label).find((text) => text !== null) ?? null;
}

/**
 * Empty entries collapse the whole map to null, so a blank row stores nothing.
 * A flattened `*` label is spread over the `locales` that have none of their
 * own first: the server's `link_shape` only accepts known locale keys.
 */
export function withLabel(link: LinkValue, locale: string, text: string, locales: string[] = []): LinkValue {
	const { "*": shared, ...own } = link.label ?? {};
	// No locales to spread over (a field without them): the shared label stays shared.
	const spread = shared === undefined ? {} : locales.length === 0 ? { "*": shared } : Object.fromEntries(locales.map((key) => [key, shared]));
	const next: LinkLabel = { ...spread, ...own };
	if (text.trim() === "") {
		delete next[locale];
	} else {
		next[locale] = text;
	}
	return { ...link, label: Object.keys(next).length === 0 ? null : next };
}

function str(raw: unknown): string | null {
	if (typeof raw === "number") {
		return String(raw);
	}
	return typeof raw === "string" && raw !== "" ? raw : null;
}

export function emptyLink(type: LinkType): LinkValue {
	return { type, page: null, value: null, anchor: null, label: null };
}
