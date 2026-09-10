/**
 * Mirrors src/Link/ExternalSchemes.php — keep the two lists identical.
 * An allowlist, not a blocklist: `javascript:`, `data:`, `file:`, `vbscript:` must never pass.
 */
export const ALLOWED_EXTERNAL_SCHEMES = ["viber", "tg", "whatsapp", "skype", "sms"] as const;

const DEEP_LINK = new RegExp(`^(?:${ALLOWED_EXTERNAL_SCHEMES.join("|")}):(?://)?\\S+$`, "i");

const HTTP_URL = /^https?:\/\/\S+$/i;

/** An http(s) URL, or an allowlisted messenger deep link. */
export function isAllowedExternal(value: string): boolean {
	return HTTP_URL.test(value) || DEEP_LINK.test(value);
}
