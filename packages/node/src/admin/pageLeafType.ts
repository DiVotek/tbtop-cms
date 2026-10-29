import type { Page } from "../content/page";
import { PageRuleError } from "../content/pageRules";
import type { PageTypes } from "../content/pageTypes";
import type { Site } from "../site";

const PAGE = "page";
const ARTICLE = "article";

/**
 * PageLeafType::resolve: a leaf's type follows its place in the tree. With
 * the type kept, a page given a category becomes an article and an article
 * whose category is removed becomes a page. Picked by hand, a page drops any
 * (stale) parent and an article must name its category. Both article rules
 * hold only while the article type requires a parent. `page` is null on create.
 */
export async function resolveLeafType(
	site: Site,
	types: PageTypes,
	page: Page | null,
	type: string,
	parentId: number | null,
): Promise<{ type: string; parentId: number | null }> {
	const isTypeKept = page !== null && page.type === type;
	if (isTypeKept && type === PAGE && parentId !== null) {
		// Only a category turns its page into an article; a host container that allows pages keeps it.
		return { type: (await site.pages.find(parentId))?.type === "category" ? ARTICLE : PAGE, parentId };
	}
	const isOrphanArticle = type === ARTICLE && parentId === null && types.has(ARTICLE) && types.get(ARTICLE).requiresParent;
	if (isTypeKept && isOrphanArticle) {
		return { type: PAGE, parentId: null };
	}
	if (type === PAGE) {
		return { type: PAGE, parentId: null };
	}
	if (isOrphanArticle) {
		throw new PageRuleError("An article needs a category.");
	}
	return { type, parentId };
}

/** PageFormOptions::subtypes: "None" then the base's subtypes; empty when it has none. */
export function subtypeOptions(types: PageTypes, base: string): { value: string; label: string }[] {
	const options = types.subtypesOf(base).map((type) => ({ value: type.key, label: type.label }));
	return options.length === 0 ? [] : [{ value: "", label: "None" }, ...options];
}

/** A form's parent id: anything but a positive decimal id (blank, "none", "1e3") is no parent. */
export function formParentId(value: unknown): number | null {
	const text = typeof value === "number" ? String(value) : typeof value === "string" ? value.trim() : "";
	return /^[1-9]\d*$/.test(text) ? Number(text) : null;
}

/** A form's subtype: blank is the plain base type. */
export const formSubtype = (value: unknown): string | null => (typeof value === "string" && value !== "" ? value : null);
