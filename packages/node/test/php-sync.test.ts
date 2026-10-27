import { describe, expect, test } from "bun:test";
import { panel } from "./panel";
import { get, makeCms } from "./support";

describe("<title> in a translated page (#311)", () => {
	test("the page's own locale name beats the default language's title", async () => {
		const { cms, ids } = await makeCms();
		const site = cms.site();
		await site.pages.update(ids.about as number, { title: { en: "About the studio" } });
		const paths = await site.pages.slugs.allFor(ids.about as number);
		const uk = await cms.loadPage(get(`/uk/${paths.uk}`));
		const en = await cms.loadPage(get(`/${paths.en}`));
		expect(uk.type === "page" && uk.props.seo.ownTitle).toBe("Про нас");
		expect(en.type === "page" && en.props.seo.ownTitle).toBe("About the studio");
	});
});

describe("the first publish stamps published_at (#310)", () => {
	const minute = 60_000;

	test("a page published with no date gets now; a republish keeps the first stamp", async () => {
		const { cms } = await makeCms(null);
		const pages = cms.site().pages;
		const created = await pages.create({ type: "page", name: { en: "Fresh" }, status: "published" });
		expect(Math.abs((created.publishedAt?.getTime() ?? 0) - Date.now())).toBeLessThan(minute);

		const draft = await pages.create({ type: "page", name: { en: "Later" } });
		expect(draft.publishedAt).toBeNull();
		expect((await pages.update(draft.id, { name: { en: "Later, renamed" } })).publishedAt).toBeNull();
		const published = await pages.update(draft.id, { status: "published" });
		expect(published.publishedAt).not.toBeNull();

		const stamp = published.publishedAt?.getTime();
		await pages.update(draft.id, { status: "draft" });
		expect((await pages.update(draft.id, { status: "published" })).publishedAt?.getTime()).toBe(stamp);
	});

	test("a date the editor sets, a future one included, is left alone", async () => {
		const { cms } = await makeCms(null);
		const pages = cms.site().pages;
		const future = new Date(Date.now() + 7 * 24 * 3600 * 1000);
		future.setMilliseconds(0);
		const scheduled = await pages.create({ type: "page", name: { en: "Soon" }, status: "published", publishedAt: future });
		expect(scheduled.publishedAt?.getTime()).toBe(future.getTime());
	});
});

type Filter = { name: string; options: { options: { value: string }[] } };

/** The table filter named `name`, wherever the structure nests it. */
function findFilter(node: unknown, name: string): Filter | undefined {
	if (Array.isArray(node)) {
		return node.map((child) => findFilter(child, name)).find((found) => found !== undefined);
	}
	if (typeof node !== "object" || node === null) {
		return undefined;
	}
	const filters = (node as { filters?: Filter[] }).filters;
	return filters?.find((filter) => filter.name === name) ?? findFilter(Object.values(node), name);
}

describe("the pages list filters (#310)", () => {
	const ids = (rows: Record<string, unknown>[]) => rows.map((row) => Number(row.id)).sort((a, b) => a - b);
	const table = async (visit: Awaited<ReturnType<typeof panel>>["visit"], query: string) =>
		((await (await visit(`/admin/pages/tables/pages?${query}`, { inertia: false })).json()) as { data: { data: Record<string, unknown>[] } }).data.data;

	test("parent offers only pages with children and keeps their children", async () => {
		const { page, visit, ids: made } = await panel();
		const parent = findFilter((await page("/admin/pages")).props.structure, "parent_id");
		expect(parent?.options.options.map((option) => Number(option.value))).toEqual([Number(made.blog)]);
		expect(ids(await table(visit, `filters[parent_id]=${made.blog}`))).toEqual([Number(made["first-post"]), Number(made["scheduled-post"])].sort((a, b) => a - b));
	});

	test("status and SEO match what the row badges say", async () => {
		const { visit, cms, ids: made } = await panel();
		await cms.site().pages.update(made.about as number, { summary: { en: "", uk: "" } });
		const drafts = await table(visit, "filters[display_state]=draft");
		expect(drafts.map((row) => row.display_state)).toEqual(drafts.map(() => "Draft"));
		expect(ids(drafts)).toContain(Number(made.draft));
		const warned = await table(visit, "filters[seo_state]=warn");
		expect(ids(warned)).toContain(Number(made.about));
		expect(warned.map((row) => row.seo_state)).toEqual(warned.map(() => "Needs work"));
		expect(ids(await table(visit, "filters[seo_state]=pass"))).not.toContain(Number(made.about));
	});
});
