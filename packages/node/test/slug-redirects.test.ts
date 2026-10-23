import { describe, expect, test } from "bun:test";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, pages: [{ key: "news", type: "category", name: { en: "News" }, children: [{ type: "article", name: { en: "Item" } }] }] };

async function rename(cms: Awaited<ReturnType<typeof makeCms>>["cms"], id: number, slug: string) {
	const page = await cms.site().pages.find(id);
	await cms.site().pages.update(id, {}, { slugs: { en: slug }, lockVersion: page?.lockVersion });
}

describe("auto-redirects on slug change", () => {
	test("a renamed page and its children leave 301s from their old paths", async () => {
		const { cms, ids } = await makeCms(fixture);
		await rename(cms, ids.news as number, "updates");

		expect(await cms.loadPage(get("/news"))).toMatchObject({ type: "redirect", status: 301, location: "/updates" });
		expect(await cms.loadPage(get("/news/item"))).toMatchObject({ type: "redirect", status: 301, location: "/updates/item" });
		const rules = await cms.site().config.db.selectFrom("tbtop_redirects").select(["from_path", "to_path", "is_auto"]).orderBy("from_path").execute();
		expect(rules.map((rule) => [rule.from_path, rule.to_path, Boolean(Number(rule.is_auto))])).toEqual([
			["news", "updates", true],
			["news/item", "updates/item", true],
		]);
	});

	test("a second rename repoints the earlier auto rule instead of chaining it", async () => {
		const { cms, ids } = await makeCms(fixture);
		await rename(cms, ids.news as number, "updates");
		await rename(cms, ids.news as number, "journal");

		const rule = await cms.site().config.db.selectFrom("tbtop_redirects").select("to_path").where("from_path", "=", "news").executeTakeFirstOrThrow();
		expect(rule.to_path).toBe("journal");
		expect(await cms.loadPage(get("/updates"))).toMatchObject({ type: "redirect", location: "/journal" });
	});

	test("renaming back to an old path drops the rule from it, so the page answers", async () => {
		const { cms, ids } = await makeCms(fixture);
		await rename(cms, ids.news as number, "updates");
		await rename(cms, ids.news as number, "news");

		expect((await cms.loadPage(get("/news"))).type).toBe("page");
		expect(await cms.loadPage(get("/updates"))).toMatchObject({ type: "redirect", location: "/news" });
	});
});
