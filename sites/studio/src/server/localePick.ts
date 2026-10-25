/** Pick a locale-keyed list from fixture JSON (site-local helper). */
export function pickLocaleList<T>(value: unknown, locale: string, fallback: string): T[] {
	if (Array.isArray(value)) {
		return value as T[];
	}
	if (value !== null && typeof value === "object") {
		const map = value as Record<string, T[]>;
		return map[locale] ?? map[fallback] ?? [];
	}
	return [];
}

export function pickLocaleRecord<T>(value: unknown, locale: string, fallback: string): T | null {
	if (value === null || typeof value !== "object" || Array.isArray(value)) {
		return null;
	}
	const map = value as Record<string, T>;
	return map[locale] ?? map[fallback] ?? null;
}
