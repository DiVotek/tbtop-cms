import { isPhpEmail, isPhpHttpUrl } from "../support/phpFilters";
import { isDict } from "../support/values";

export type LinkType = "page" | "external" | "email" | "phone";
export type ResolvedLink = { href: string; type: LinkType; label: string };

const TYPES: LinkType[] = ["page", "external", "email", "phone"];
export const EXTERNAL_SCHEMES = ["viber", "tg", "whatsapp", "skype", "sms"];

/** A parsed link field value (LinkValue.php). */
export type LinkValue = {
	type: LinkType;
	page: string | null;
	value: string | null;
	anchor: string | null;
	labels: Record<string, string> | null;
};

const scalar = (raw: unknown): string | null => {
	if (typeof raw === "number" && Number.isInteger(raw)) {
		return String(raw);
	}
	if (typeof raw !== "string") {
		return null;
	}
	const trimmed = raw.trim();
	return trimmed === "" ? null : trimmed;
};

export function parseLink(raw: unknown): LinkValue | null {
	if (!isDict(raw) || !TYPES.includes(raw.type as LinkType)) {
		return null;
	}
	const type = raw.type as LinkType;
	return {
		type,
		page: type === "page" ? scalar(raw.page) : null,
		value: type === "page" ? null : scalar(raw.value),
		anchor: type === "page" ? scalar(raw.anchor) : null,
		labels: labels(raw.label),
	};
}

function labels(raw: unknown): Record<string, string> | null {
	if (typeof raw === "string" || typeof raw === "number") {
		const one = scalar(raw);
		return one === null ? null : { "*": one };
	}
	if (!isDict(raw)) {
		return null;
	}
	const out: Record<string, string> = {};
	for (const [locale, text] of Object.entries(raw)) {
		const one = scalar(text);
		if (one !== null) {
			out[locale] = one;
		}
	}
	return Object.keys(out).length === 0 ? null : out;
}

export function linkLabel(link: LinkValue, locale: string, fallback: string): string | null {
	if (link.labels === null) {
		return null;
	}
	for (const key of [locale, fallback, "*"]) {
		const label = link.labels[key];
		if (label !== undefined) {
			return label;
		}
	}
	return Object.values(link.labels)[0] ?? null;
}

export function telNumber(value: string): string {
	const digits = value.replace(/[^0-9]/g, "");
	return value.trim().startsWith("+") ? `+${digits}` : digits;
}

/** LinkTarget::isWellFormed. */
export function isWellFormed(link: LinkValue): boolean {
	switch (link.type) {
		case "page":
			return link.page !== null && /^\d+$/.test(link.page) && (link.anchor === null || /^[A-Za-z0-9_-]+$/.test(link.anchor));
		case "external":
			return link.value !== null && externalIsValid(link.value);
		case "email":
			return link.value !== null && isPhpEmail(link.value);
		case "phone": {
			if (link.value === null || !/^\+?[0-9 ()-]{5,40}$/.test(link.value)) {
				return false;
			}
			const digits = link.value.replace(/[^0-9]/g, "").length;
			return digits >= 6 && digits <= 20;
		}
	}
}

/** An http(s) URL by FILTER_VALIDATE_URL, or a messenger deep link (ExternalSchemes). */
function externalIsValid(value: string): boolean {
	return isPhpHttpUrl(value) || new RegExp(`^(?:${EXTERNAL_SCHEMES.join("|")}):(?://)?\\S+$`, "i").test(value);
}

export const LINK_TYPES: readonly LinkType[] = TYPES;

const LABEL_MAX = 255;

/**
 * LinkShapeValidator::passes without the page lookup: null and an empty value
 * (what an untouched field posts) pass; otherwise a parsable link of an
 * allowed type, a label within 255 characters keyed by known locales, and a
 * well-formed target. Returns the parsed link for the caller's page check.
 */
export function linkShape(value: unknown, allowedTypes: string[], locales: string[]): { ok: boolean; link: LinkValue | null } {
	if (value === null || value === undefined || (Array.isArray(value) && value.length === 0) || (isDict(value) && Object.keys(value).length === 0)) {
		return { ok: true, link: null };
	}
	const link = parseLink(value);
	if (link === null || (allowedTypes.length > 0 && !allowedTypes.includes(link.type))) {
		return { ok: false, link: null };
	}
	const ok = labelIsValid((value as Record<string, unknown>).label, locales) && isWellFormed(link);
	return { ok, link: ok ? link : null };
}

/** Read raw, not off LinkValue: the length and known-locale gates are about what was posted. */
function labelIsValid(label: unknown, locales: string[]): boolean {
	if (label === null || label === undefined) {
		return true;
	}
	if (typeof label === "string") {
		return [...label].length <= LABEL_MAX;
	}
	if (!isDict(label)) {
		return false;
	}
	return Object.entries(label).every(
		([locale, text]) => locales.includes(locale) && (text === null || (typeof text === "string" && [...text].length <= LABEL_MAX)),
	);
}

/** The page ids of every stored page link anywhere in `value`, nested lists and locale maps included. */
export function linkedPageIds(value: unknown, out: number[] = []): number[] {
	if (Array.isArray(value)) {
		for (const item of value) {
			linkedPageIds(item, out);
		}
		return out;
	}
	if (!isDict(value)) {
		return out;
	}
	const link = parseLink(value);
	if (link?.type === "page" && link.page !== null && /^[1-9]\d*$/.test(link.page)) {
		out.push(Number(link.page));
	}
	for (const item of Object.values(value)) {
		linkedPageIds(item, out);
	}
	return out;
}
