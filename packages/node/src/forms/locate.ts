import type { BlockKind, SubmitContext, SubmitHandler } from "../blocks/registry";
import { flattenLocales } from "../blocks/locale";
import type { Page } from "../content/page";
import type { Site } from "../site";
import { type Dict, isDict } from "../support/values";

/** A form block found for a submit: its handler, its flattened editor data, the submit context. */
export type LocatedForm = { block: BlockKind; submit: SubmitHandler; data: Dict; ctx: SubmitContext };

/**
 * FormBlockLocator: the page's own rows first, then the header/footer
 * slots. Only an active row of a registered kind with a submit handler
 * counts; a row placed by reference reads the shared block's data.
 */
export async function locateForm(site: Site, page: Page, blockId: string, locale: string): Promise<LocatedForm | null> {
	const layouts = await site.layouts.content();
	const row = findRow(page.content ?? [], blockId) ?? findRow(layouts.header, blockId) ?? findRow(layouts.footer, blockId);
	if (row === null || typeof row.kind !== "string" || !site.blocks.has(row.kind)) {
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
	const fallback = await site.languages.defaultLocale();
	const locales = (await site.languages.all()).map((language) => language.locale);
	return {
		block,
		submit: block.submit,
		data: flattenLocales(data, locale, fallback, locales, block.translatableFields()),
		ctx: { locale, fallbackLocale: fallback, page },
	};
}

function findRow(content: unknown[], blockId: string): Dict | null {
	for (const row of content) {
		if (isDict(row) && row.id === blockId && row.active !== false) {
			return row;
		}
	}
	return null;
}
