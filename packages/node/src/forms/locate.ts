import type { BlockKind, SubmitContext, SubmitHandler } from "../blocks/registry";
import { flattenLocales } from "../blocks/locale";
import { formActionProps } from "../blocks/resolver";
import type { Page } from "../content/page";
import type { Site } from "../site";
import { translate } from "../support/strings";
import { type Dict, isDict } from "../support/values";

/** A form block found for a submit: its handler, its flattened editor data, the submit context. */
export type LocatedForm = { block: BlockKind; submit: SubmitHandler; data: Dict; ctx: SubmitContext };

/**
 * FormBlockLocator: the page's own rows (its template's without them) first, then the header/footer
 * slots. Only an active row of a registered kind with a submit handler
 * counts; a row placed by reference reads the shared block's data. With no
 * row of that id, the id is tried as a form name (HasForm), with no editor data.
 */
export async function locateForm(site: Site, page: Page, blockId: string, locale: string): Promise<LocatedForm | null> {
	const layouts = await site.layouts.content();
	// A page with no blocks of its own renders its template: a form there posts too.
	const row = findRow(page.content ?? (await site.templates.contentFor(page)) ?? [], blockId) ?? findRow(layouts.header, blockId) ?? findRow(layouts.footer, blockId);
	const fallback = await site.languages.defaultLocale();
	const ctx: SubmitContext = { locale, fallbackLocale: fallback, page, t: (key, params) => translate(key, locale, fallback, site.config.strings, params) };
	if (row === null) {
		const named = namedForm(site, blockId);
		return named?.submit == null ? null : { block: named, submit: named.submit, data: {}, ctx };
	}
	if (typeof row.kind !== "string" || !site.blocks.has(row.kind)) {
		return null;
	}
	const block = site.blocks.get(row.kind);
	if (block.submit === null) {
		return null;
	}
	const data = await site.sharedBlocks.dataOf(row, block.kind);
	if (data === null) {
		return null;
	}
	const locales = (await site.languages.all()).map((language) => language.locale);
	return { block, submit: block.submit, data: flattenLocales(data, locale, fallback, locales, block.translatableFields()), ctx };
}

/** Forms::get: the block kind that declares this form name, or null. */
export const namedForm = (site: Site, name: string): BlockKind | null => site.blocks.all().find((block) => block.formName === name) ?? null;

/**
 * Forms::props: `action` and `honeypotField` for a named form posted from
 * `page`, the pair a placed form block gets in its props. Throws for a name
 * no block declares, so a typo fails loudly instead of posting to a 404.
 */
export async function namedFormProps(site: Site, name: string, page: Page | number, locale?: string): Promise<{ action: string; honeypotField?: string }> {
	if (namedForm(site, name) === null) {
		throw new Error(`Form [${name}] is not registered.`);
	}
	return formActionProps(site, typeof page === "number" ? page : page.id, name, locale ?? (await site.languages.defaultLocale()));
}

function findRow(content: unknown[], blockId: string): Dict | null {
	for (const row of content) {
		if (isDict(row) && row.id === blockId && row.active !== false) {
			return row;
		}
	}
	return null;
}
