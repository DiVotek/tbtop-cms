import { isPhpEmail, isPhpHttpUrl } from "../support/phpFilters";
import { isDict } from "../support/values";

export type LinkType = "page" | "external" | "email" | "phone";
export type ResolvedLink = { href: string; type: LinkType; label: string };

const TYPES: LinkType[] = ["page", "external", "email", "phone"];
const EXTERNAL_SCHEMES = ["viber", "tg", "whatsapp", "skype", "sms"];

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
