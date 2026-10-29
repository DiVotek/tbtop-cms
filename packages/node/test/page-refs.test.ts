import { describe, expect, test } from "bun:test";
import { definePageType, pagesField } from "../src";
import { rebuildPageRefs } from "../src/content/pageRefIndex";
import { makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const service = definePageType({ key: "service", form: () => [pagesField("experts").of("expert")] });
const expert = definePageType({
	key: "expert",
	form: () => [],
	resolveFields: async (_input, ctx) => ({ services: await ctx.referencedBy("experts", "service") }),
});
const fixture = {
	languages: en,
	pages: [
		{ key: "ada", type: "expert", name: { en: "Ada" }, status: "published" as const },
		{ key: "zed", type: "service", name: { en: "Zed audit" }, status: "published" as const },
		{ key: "app", type: "service", name: { en: "Apps" }, status: "published" as const },
		{ key: "draft", type: "service", name: { en: "Draft" }, status: "draft" as const },
	],
};

async function setup() {
	const made = await makeCms(fixture, { pageTypes: [service, expert] });
	const pages = made.cms.site().pages;
	for (const key of ["zed", "app", "draft"]) {
		const page = await pages.find(made.ids[key] as number);
		await pages.update(page?.id as number, { fields: { experts: [String(made.ids.ada)] } }, { lockVersion: page?.lockVersion });
	}
	return made;
}

describe("page references index", () => {
	test("a pages field's ids are indexed on save; referencedBy lists live sources by sort order", async () => {
		const { cms, ids } = await setup();
		const site = cms.site();
		const ada = await site.pages.find(ids.ada as number);
		expect((await site.referencedBy(ada, "experts")).map((page) => page.name.en)).toEqual(["Zed audit", "Apps"]);
		expect(await site.referencedBy(ada, "experts", { from: "expert" })).toEqual([]);
		expect(await site.referencedBy(null, "experts")).toEqual([]);
		expect((await site.referencedByLinks(ada, "experts")).map((ref) => ref.name)).toEqual(["Zed audit", "Apps"]);
	});

	test("a page type's resolveFields reads its back references through ctx.referencedBy", async () => {
		const { cms } = await setup();
		const result = await cms.loadPage(new Request("https://site.test/ada"));
		if (result.type !== "page") throw new Error(result.type);
		expect((result.props.page.fields as { services: { name: string }[] }).services.map((ref) => ref.name)).toEqual(["Zed audit", "Apps"]);
	});

	test("a save that drops the id drops the row; rebuild rewrites the index from the pages", async () => {
		const { cms, db, ids } = await setup();
		const pages = cms.site().pages;
		const zed = await pages.find(ids.zed as number);
		await pages.update(ids.zed as number, { fields: { experts: [] } }, { lockVersion: zed?.lockVersion });
		expect((await cms.site().referencedBy(await pages.find(ids.ada as number), "experts")).map((page) => page.name.en)).toEqual(["Apps"]);

		await db.deleteFrom("tbtop_page_refs").execute();
		expect(await rebuildPageRefs(db, cms.site().pageTypes)).toBe(2);
	});
});
