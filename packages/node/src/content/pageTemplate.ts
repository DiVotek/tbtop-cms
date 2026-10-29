import type { Page } from "./page";
import type { PageTypes } from "./pageTypes";
import { blockId, type Dict, isDict } from "../support/values";

/**
 * TypeTemplateContent::contentFor: the type's template() (the subtype's first) with a fresh id
 * per block, and active/sort/data defaults the template may leave out. The
 * frontend renders template() only for a null content, so an admin-made page
 * starts with the rows instead of `[]`.
 */
export function templateContent(types: PageTypes, page: Page): Dict[] {
	return (types.templateFor(page) ?? [])
		.filter(isDict)
		.map((block, index) => ({ active: true, sort: index, data: {}, ...block, id: blockId() }));
}

/** The page about to be created, for template(): PHP passes the unsaved model. */
export const draftPage = (input: Pick<Page, "type" | "parentId" | "subtype" | "name">): Page => ({
	id: 0,
	sourceId: null,
	status: "draft",
	publishedAt: null,
	unpublishAt: null,
	content: null,
	fields: null,
	starterBlocks: null,
	heading: {},
	summary: {},
	body: {},
	image: null,
	seo: {},
	isIndexable: true,
	isSystem: false,
	errorClass: null,
	sortOrder: 0,
	lockVersion: 0,
	createdBy: null,
	updatedBy: null,
	createdAt: null,
	updatedAt: null,
	...input,
});
