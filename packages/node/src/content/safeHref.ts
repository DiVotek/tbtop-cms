import { EXTERNAL_SCHEMES } from "./link";

/** SafeHref::SCHEMES: the schemes a free-text link may carry besides ExternalSchemes. */
const SCHEMES = ["http", "https", "mailto", "tel"];

/**
 * SafeHref::passes: what a browser parses (leading C0 controls and spaces
 * dropped, tabs and newlines removed anywhere, so `java\tscript:` is
 * `javascript:`). Without a scheme it is a relative URL (`blog`,
 * `contact#form`, `?ref=x`) and passes; with one, only http(s), mailto, tel
 * or an ExternalSchemes deep link pass.
 */
export function safeHref(value: string | null): string | null {
	if (value === null) {
		return null;
	}
	const parsed = value.replace(/^[\x00-\x20]+/, "").replace(/[\t\n\r]/g, "");
	const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(parsed)?.[1]?.toLowerCase();
	if (scheme === undefined) {
		return value;
	}
	const external = new RegExp(`^(?:${EXTERNAL_SCHEMES.join("|")}):(?://)?[^\\t\\n\\v\\f\\r ]+$`, "i");
	return SCHEMES.includes(scheme) || external.test(parsed) ? value : null;
}

/*
 * The same check as one regex over the raw value, for the admin's `regex:`
 * rule (the validator has no closure rules, and a host rule would need every
 * host to register it). Tabs and newlines may sit between any two
 * characters of the scheme, as the browser drops them; the admin client
 * gets the pattern without flags, so it spells out its own case-insensitivity.
 */
const GAP = "[\\t\\n\\r]*";
const anyCase = (word: string): string => [...word].map((ch) => `[${ch.toLowerCase()}${ch.toUpperCase()}]`).join(GAP);
const LEAD = "[\\x00-\\x20]*";
const ANY_SCHEME = `${LEAD}[a-zA-Z](?:${GAP}[a-zA-Z0-9+.\\-])*${GAP}:`;
const SAFE_SCHEME = `${LEAD}(?:${SCHEMES.map(anyCase).join("|")})${GAP}:[\\s\\S]*`;
/** ExternalSchemes::matches on the parsed value: `\S` there is PCRE's ASCII whitespace, tabs and newlines being already gone. */
const DEEP_LINK = `${LEAD}(?:${EXTERNAL_SCHEMES.map(anyCase).join("|")})${GAP}:(?:/${GAP}/)?${GAP}(?:[^\\t\\n\\v\\f\\r ]${GAP})+`;

/** The Laravel `regex:` rule for an admin field that takes such a link (SafeHref::RULE). */
export const safeHrefRule = `regex:/^(?:(?!${ANY_SCHEME})[\\s\\S]*|${SAFE_SCHEME}|${DEEP_LINK})$/`;
