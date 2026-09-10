import { isAllowedExternal } from "./schemes";
import type { LinkValue } from "./types";

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

/** Mirrors PHONE_PATTERN in src/Link/LinkShapeValidator.php; the 6-20 digit count is checked below. */
const PHONE_SHAPE = /^\+?[0-9 ()-]{5,40}$/;

/** Bare fragment, no leading `#`. Mirrors ANCHOR_PATTERN in src/Link/LinkShapeValidator.php. */
const ANCHOR = /^[A-Za-z0-9_-]+$/;

/** Mirrors LABEL_MAX in src/Link/LinkShapeValidator.php. */
const LABEL_MAX = 255;

export type LinkErrorKey = "page" | "external" | "email" | "phone" | "anchor" | "label";

/**
 * Preview of src/Link/LinkShapeValidator.php. Advisory only — the server is the
 * authority; this exists so the author sees the problem before saving.
 */
export function linkError(link: LinkValue): LinkErrorKey | null {
	if (isLabelTooLong(link)) {
		return "label";
	}

	if (link.type === "page") {
		// An unpicked page is not yet wrong, the same as an empty value below;
		// `required` is the server's to enforce. A typed anchor still is.
		return link.anchor !== null && !ANCHOR.test(link.anchor) ? "anchor" : null;
	}

	const value = link.value?.trim() ?? "";

	// An untouched field is not yet wrong — `required` is the server's to enforce.
	if (value === "") {
		return null;
	}

	if (link.type === "external") {
		return isAllowedExternal(value) ? null : "external";
	}
	if (link.type === "email") {
		return EMAIL.test(value) ? null : "email";
	}
	return isPhone(value) ? null : "phone";
}

function isLabelTooLong(link: LinkValue): boolean {
	return Object.values(link.label ?? {}).some((text) => (text ?? "").length > LABEL_MAX);
}

function isPhone(value: string): boolean {
	if (!PHONE_SHAPE.test(value)) {
		return false;
	}
	const digits = value.replace(/\D/g, "").length;
	return digits >= 6 && digits <= 20;
}
