/** Mirrors the PHP stored shape — src/Link/LinkValue.php. `page` is the id as a string. */
export const LINK_TYPES = ["page", "external", "email", "phone"] as const;

export type LinkType = (typeof LINK_TYPES)[number];

export interface LinkValue {
	type: LinkType;
	page: string | null;
	value: string | null;
	anchor: string | null;
}

export interface LinkOptions {
	/** Rendered by the PHP field from the admin prefix — Link::toNode(). */
	searchEndpoint?: string;
	types?: LinkType[];
	labels?: Partial<Record<LinkType, string>>;
	placeholders?: Partial<Record<Exclude<LinkType, "page">, string>>;
	errors?: Partial<Record<LinkType | "anchor", string>>;
	anchorLabel?: string;
	anchorPlaceholder?: string;
	pagePlaceholder?: string;
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
	};
}

function str(raw: unknown): string | null {
	if (typeof raw === "number") {
		return String(raw);
	}
	return typeof raw === "string" && raw !== "" ? raw : null;
}

export function emptyLink(type: LinkType): LinkValue {
	return { type, page: null, value: null, anchor: null };
}
