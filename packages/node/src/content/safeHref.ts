import { EXTERNAL_SCHEMES } from "./link";

/** `[hH][tT]…`: the rule goes to the admin client without its flags, so the pattern carries its own case-insensitivity. */
const anyCase = (word: string): string => [...word].map((ch) => `[${ch.toLowerCase()}${ch.toUpperCase()}]`).join("");

/** No whitespace or control characters anywhere: browsers strip them, so `java\tscript:` would still run. */
const CH = "[^\\s\\x00-\\x1f\\x7f]";

/**
 * A link a visitor may click: http(s) with a host, a site-relative `/…` (not
 * `//host` or `/\host`), a `#…` anchor, `mailto:`, `tel:` or a messenger
 * scheme (ExternalSchemes). An allowlist: `javascript:`, `data:`, `vbscript:`
 * and anything unknown fail.
 */
export const SAFE_HREF = [
	`${anyCase("http")}${anyCase("s")}?://[^\\s\\x00-\\x1f\\x7f/?#\\\\]+${CH}*`,
	`/(?![/\\\\])${CH}*`,
	`#${CH}*`,
	`(?:${["mailto", "tel"].map(anyCase).join("|")}):${CH}+`,
	`(?:${EXTERNAL_SCHEMES.map(anyCase).join("|")}):(?://)?${CH}+`,
]
	.map((branch) => `(?:${branch})`)
	.join("|");

const SAFE = new RegExp(`^(?:${SAFE_HREF})$`);

/** The Laravel `regex:` rule for an admin field that takes such a link. */
export const safeHrefRule = `regex:/^(?:${SAFE_HREF})$/`;

/** The href when it is safe to render (see SAFE_HREF), else null. */
export const safeHref = (value: string | null): string | null => (value !== null && SAFE.test(value) ? value : null);
