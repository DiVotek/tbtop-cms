import { isDict } from "../support/values";

/** LocalizedSeo::value: a plain string, or the locale's entry of a locale map. */
export function localized(raw: unknown, locale: string): string {
	if (typeof raw === "string") {
		return raw;
	}
	if (isDict(raw)) {
		const value = raw[locale];
		return typeof value === "string" ? value : "";
	}
	return "";
}

export function localizedWithFallback(raw: unknown, locale: string, fallback: string): string {
	const value = localized(raw, locale);
	if (value !== "" || fallback === "" || fallback === locale) {
		return value;
	}
	return localized(raw, fallback);
}
