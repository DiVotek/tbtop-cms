import type { Selectable } from "kysely";
import type { PagesTable } from "../db/schema";
import { bool, type Dict, decodeJson, isBlank, isDict, parseDate } from "../support/values";

export const TRANSLATABLE = ["name", "title", "heading", "summary", "body"] as const;
export type TranslatableField = (typeof TRANSLATABLE)[number];

/** A spatie-translatable column: one value per locale. */
export type Translations = Record<string, unknown>;

export type PageStatus = "draft" | "published";

/** A page row with its JSON columns decoded (Page.php). */
export type Page = {
	id: number;
	type: string;
	subtype: string | null;
	sourceId: string | null;
	parentId: number | null;
	status: PageStatus;
	publishedAt: Date | null;
	unpublishAt: Date | null;
	content: unknown[] | null;
	fields: Dict | null;
	starterBlocks: Dict | null;
	name: Translations;
	title: Translations;
	heading: Translations;
	summary: Translations;
	body: Translations;
	image: string | null;
	seo: Dict;
	isIndexable: boolean;
	isSystem: boolean;
	errorClass: string | null;
	sortOrder: number;
	lockVersion: number;
	createdBy: string | number | null;
	updatedBy: string | number | null;
	createdAt: Date | null;
	updatedAt: Date | null;
};

const translations = (raw: unknown): Translations => {
	const value = decodeJson(raw);
	return isDict(value) ? value : {};
};

export function pageFromRow(row: Selectable<PagesTable>): Page {
	const content = decodeJson(row.content);
	const fields = decodeJson(row.fields);
	const starter = decodeJson(row.starter_blocks);
	const seo = decodeJson(row.seo);
	return {
		id: Number(row.id),
		type: row.type,
		subtype: row.subtype,
		sourceId: row.source_id,
		parentId: row.parent_id === null ? null : Number(row.parent_id),
		status: row.status === "published" ? "published" : "draft",
		publishedAt: parseDate(row.published_at),
		unpublishAt: parseDate(row.unpublish_at),
		content: Array.isArray(content) ? content : null,
		fields: isDict(fields) ? fields : null,
		starterBlocks: isDict(starter) ? starter : null,
		name: translations(row.name),
		title: translations(row.title),
		heading: translations(row.heading),
		summary: translations(row.summary),
		body: translations(row.body),
		image: row.image,
		seo: isDict(seo) ? seo : {},
		isIndexable: bool(row.is_indexable),
		isSystem: bool(row.is_system),
		errorClass: row.error_class,
		sortOrder: Number(row.sort_order),
		lockVersion: Number(row.lock_version),
		createdBy: row.created_by,
		updatedBy: row.updated_by,
		createdAt: parseDate(row.created_at),
		updatedAt: parseDate(row.updated_at),
	};
}

/** Published and inside its schedule window (Page::isLive / scopeLive). */
export function isLive(page: Page, now = new Date()): boolean {
	return (
		page.status === "published" &&
		(page.publishedAt === null || page.publishedAt <= now) &&
		(page.unpublishAt === null || page.unpublishAt > now)
	);
}

/** PageTranslation::get: the locale's value, else the fallback's, skipping blank ones. */
export function translation(page: Page, field: TranslatableField, locale: string, fallback: string): unknown {
	for (const candidate of new Set([locale, fallback])) {
		const value = page[field][candidate];
		if (!isBlank(value)) {
			return value;
		}
	}
	return null;
}

export function translationText(page: Page, field: TranslatableField, locale: string, fallback: string): string | null {
	const value = translation(page, field, locale, fallback);
	return typeof value === "string" ? value : null;
}

/** PageTranslation::firstText: the first non-blank string among `fields`, locale before fallback. */
export function firstText(page: Page, fields: TranslatableField[], locale: string, fallback: string): string {
	for (const candidate of new Set([locale, fallback])) {
		for (const field of fields) {
			const value = page[field][candidate];
			if (typeof value === "string" && !isBlank(value)) {
				return value;
			}
		}
	}
	return "";
}
