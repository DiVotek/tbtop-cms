import { type Dict, isDict } from "../support/values";
import type { Page } from "./page";
import type { PageTypes } from "./pageTypes";
import type { Settings } from "./settings";

/** The Setting holding a page type's stored template: `page_templates.{type key}`. */
export const templateKey = (type: string): string => `page_templates.${type}`;

/**
 * Stored templates (the block content every page of a type renders when it
 * has none of its own): a host's admin page edits one with `blockEditor`,
 * and it wins over the type's code `template()`. Kept as Settings, so a
 * frontend read goes through the content cache and a save bumps it.
 */
export class PageTemplates {
	constructor(
		private readonly settings: Settings,
		private readonly types: PageTypes,
	) {}

	/** The stored rows of a type (base or subtype); null when none was saved. */
	async get(type: string): Promise<Dict[] | null> {
		const value = await this.settings.get(templateKey(type), null);
		return Array.isArray(value) ? value.filter(isDict) : null;
	}

	/** Replaces a registered type's stored template. */
	async save(type: string, rows: unknown[]): Promise<void> {
		if (!this.types.has(type)) {
			throw new Error(`Page type [${type}] is not registered.`);
		}
		await this.settings.set(templateKey(type), rows.filter(isDict));
	}

	/** Back to the type's code template. */
	async reset(type: string): Promise<void> {
		await this.settings.forget(templateKey(type));
	}

	/**
	 * Block content for a page with none of its own: the stored template of
	 * its subtype, then of its type, then the code template (`templateFor`).
	 */
	async contentFor(page: Page): Promise<unknown[] | null> {
		const keys = [...(page.subtype !== null && page.subtype !== "" ? [page.subtype] : []), page.type];
		for (const key of keys) {
			const stored = await this.get(key);
			if (stored !== null) {
				return stored;
			}
		}
		return codeTemplate(this.types, page);
	}
}

/**
 * The type's code `template()` with an id on every row (`template-{index}`
 * where it set none): the editor's form, a form block's post and the
 * render name a row alike, request after request.
 */
export function codeTemplate(types: PageTypes, page: Page): Dict[] | null {
	const rows = types.templateFor(page);
	return rows === null
		? null
		: rows.filter(isDict).map((row, index) => ({ active: true, sort: index, data: {}, ...row, id: typeof row.id === "string" ? row.id : `template-${index}` }));
}
