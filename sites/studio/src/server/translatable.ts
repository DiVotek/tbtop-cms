/** Read a translatable block field from stored fixture/editor JSON. */
export function fieldText(input: Record<string, unknown>, key: string, locale: string, fallback: string): string | null {
	const value = input[key];
	if (typeof value === "string") {
		return value;
	}
	if (value !== null && typeof value === "object" && !Array.isArray(value)) {
		const map = value as Record<string, string>;
		return map[locale] ?? map[fallback] ?? Object.values(map)[0] ?? null;
	}
	return null;
}
