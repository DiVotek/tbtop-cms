import type { Page } from "@tbtop/cms";

type Field = "name" | "title" | "heading" | "summary";

export function pageText(page: Page, field: Field, locale: string, fallback: string): string {
	for (const candidate of new Set([locale, fallback])) {
		const value = page[field][candidate];
		if (typeof value === "string" && value.trim() !== "") {
			return value;
		}
	}
	return "";
}
