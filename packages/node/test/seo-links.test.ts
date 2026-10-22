import { describe, expect, test } from "bun:test";
import { get, makeCms } from "./support";

async function pageAt(cms: Awaited<ReturnType<typeof makeCms>>["cms"], path: string) {
	const result = await cms.loadPage(get(path));
	if (result.type !== "page") {
		throw new Error(`expected a page at ${path}, got ${result.type}`);
	}
	return result.props;
}

describe("page types", () => {
	test("a subtype without its own schemaTypes inherits its base type's (F12)", async () => {
		const { cms, ids } = await makeCms(undefined, { pageTypes: [{ key: "news", base: "article" }] });
		await cms.site().pages.update(ids["first-post"] ?? 0, { subtype: "news" });
		const { seo } = await pageAt(cms, "/blog/first-post");
		expect(seo.ogType).toBe("article");
		expect(seo.jsonLd.map((node) => node["@type"])).toContain("Article");
	});
});

describe("seo templates", () => {
	test("placeholders inside substituted values stay literal, like PHP strtr (F13)", async () => {
		const { cms, ids } = await makeCms();
		await cms.site().pages.update(ids.about ?? 0, { title: { en: "Use {site_name} and {sep}" } });
		await cms.site().settings.set("site.name", "Acme {title}");
		const { seo } = await pageAt(cms, "/about-us");
		expect(seo.title).toBe("Use {site_name} and {sep} — Acme {title}");
	});
});
