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

describe("links", () => {
	const external = (value: string) => ({ type: "external", value });
	const email = (value: string) => ({ type: "email", value });

	test("an external link must pass FILTER_VALIDATE_URL (F14)", async () => {
		const { cms } = await makeCms();
		const site = cms.site();
		for (const value of ["https://example.com/a?b=1#c", "http://user:pw@example.com:8080/", "https://xn--bcher-kva.de", "http://[::1]/", "tg://resolve?domain=x"]) {
			expect((await site.link(external(value), "en"))?.href).toBe(value);
		}
		// PHP rejects a missing `//`, an IDN host, a non-ASCII path, a space, an underscore host.
		for (const value of ["https:example.com", "https://bücher.de", "https://example.com/ä", "https://exa mple.com", "https://a_b.com", "http://-a.com"]) {
			expect(await site.link(external(value), "en")).toBeNull();
		}
	});

	test("an email link must pass FILTER_VALIDATE_EMAIL (F14)", async () => {
		const { cms } = await makeCms();
		const site = cms.site();
		expect((await site.link(email("hello@example.test"), "en"))?.href).toBe("mailto:hello@example.test");
		for (const value of ['"><img@x.y', "a..b@example.com", "a@-example.com", "ü@example.com", "a@b@example.com"]) {
			expect(await site.link(email(value), "en")).toBeNull();
		}
	});
});
