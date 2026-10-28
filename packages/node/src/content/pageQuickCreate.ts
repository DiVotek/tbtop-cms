import { HttpError } from "@tbtop/admin";
import type { Site } from "../site";
import { draftPage, templateContent } from "./pageTemplate";

/**
 * PageQuickCreate: the page `pagesField(...).creatable()` makes from a name
 * typed into the select. Published, of the field's one `of()` type, under
 * the parent of the newest page of that type (a new tag lands next to the
 * others, under `/tags`), with slugs derived from the name like any new
 * page. The first page of the type is made in Pages: until then the select
 * refuses, since only then is it known where the rest go.
 */
export async function quickCreatePage(site: Site, pairs: string[], name: string, locale: string): Promise<{ value: string; label: string }> {
	if (pairs.length !== 1) {
		throw new Error("pagesField().creatable() needs exactly one of() type to create.");
	}
	const [type = "", subtype = null] = (pairs[0] ?? "").split("/", 2);
	const sibling = await site.pages
		.query()
		.select("parent_id")
		.where("type", "=", type)
		.where((eb) => (subtype === null ? eb("subtype", "is", null) : eb("subtype", "=", subtype)))
		.orderBy("id", "desc")
		.executeTakeFirst();
	if (sibling === undefined) {
		throw new HttpError(422, "The given data was invalid.", {
			errors: { name: ["Create the first page of this type in Pages: new ones go under the same parent."] },
		});
	}
	const label = name.trim();
	const tree = { type, subtype, parentId: sibling.parent_id === null ? null : Number(sibling.parent_id), name: { [locale]: label } };
	const page = await site.pages.create({ ...tree, status: "published", isIndexable: true, content: templateContent(site.pageTypes, draftPage(tree)) });
	return { value: String(page.id), label };
}
