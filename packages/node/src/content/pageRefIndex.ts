import { S } from "@tbtop/admin";
import type { Kysely } from "kysely";
import { PagesField } from "../blocks/cmsFields";
import { formFields } from "../blocks/fields";
import type { CmsDatabase } from "../db/schema";
import { type Page, pageFromRow } from "./page";
import { pageIds } from "./pageRefs";
import type { PageTypes } from "./pageTypes";

/** The page-type fields of kind pages a page has; a subtype's declaration of a shared name wins (PageRefIndex::pagesFields). */
export function pagesFieldNames(types: PageTypes, page: Page): string[] {
	const declared = new Map<string, boolean>();
	for (const type of types.forPage(page)) {
		for (const field of formFields(type.form(new S()))) {
			declared.set(field.name, field instanceof PagesField);
		}
	}
	return [...declared].filter(([, isPages]) => isPages).map(([name]) => name);
}

function rowsFor(types: PageTypes, page: Page) {
	const stored = page.fields ?? {};
	return pagesFieldNames(types, page).flatMap((field) =>
		pageIds(stored[field])
			// A page never lists itself among its own back references.
			.filter((target) => target !== page.id)
			.map((target, position) => ({ page_id: page.id, field, target_id: target, position })),
	);
}

/**
 * `tbtop_page_refs` (PageRefIndex.php): one row per id a page's type field
 * of kind pages holds, rewritten on every save, so "who references me
 * through field X" is an indexed lookup. Block data is never indexed;
 * trashed sources keep their rows and readers filter on live.
 */
export async function writePageRefs(db: Kysely<CmsDatabase>, types: PageTypes, page: Page): Promise<void> {
	const rows = rowsFor(types, page);
	await db.deleteFrom("tbtop_page_refs").where("page_id", "=", page.id).execute();
	if (rows.length > 0) {
		await db.insertInto("tbtop_page_refs").values(rows).execute();
	}
}

/** Rewrites the whole index from every page, trashed included; the rows written. */
export async function rebuildPageRefs(db: Kysely<CmsDatabase>, types: PageTypes): Promise<number> {
	await db.deleteFrom("tbtop_page_refs").execute();
	let count = 0;
	for (const row of await db.selectFrom("tbtop_pages").selectAll().orderBy("id").execute()) {
		const rows = rowsFor(types, pageFromRow(row));
		for (let start = 0; start < rows.length; start += 500) {
			await db.insertInto("tbtop_page_refs").values(rows.slice(start, start + 500)).execute();
		}
		count += rows.length;
	}
	return count;
}
