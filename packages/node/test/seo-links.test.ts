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
