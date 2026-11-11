import { describe, expect, test } from "bun:test";
import { definePageType, type LlmsTxtSection } from "../src";
import { get, makeCms } from "./support";
import { panel } from "./panel";

const llms = async (cms: Awaited<ReturnType<typeof makeCms>>["cms"]) => (await cms.handle(get("/llms.txt")))?.text();

async function on(config: Parameters<typeof makeCms>[1] = {}) {
	const made = await makeCms(undefined, config);
	await made.cms.site().settings.set("seo.llms_txt.enabled", true);
	await made.cms.site().settings.set("site.name", "Acme");
	return made;
}

describe("llms.txt", () => {
	test("is not served until an editor turns it on", async () => {
		const { cms } = await makeCms();
		expect((await cms.handle(get("/llms.txt")))?.status).toBe(404);
	});

	test("is not served while indexing is off", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.indexing_enabled", false);
		expect((await cms.handle(get("/llms.txt")))?.status).toBe(404);
	});

	test("llmsTxt.enabled: false leaves the URL to the host", async () => {
		const { cms } = await on({ llmsTxt: { enabled: false } });
		expect(await cms.handle(get("/llms.txt"))).toBeNull();
	});

	test("the site name, summary, contacts and live pages by type, then the sitemap", async () => {
		const { cms } = await on();
		const res = await cms.handle(get("/llms.txt"));
		expect(res?.headers.get("Content-Type")).toBe("text/plain; charset=utf-8");
		expect(await res?.text()).toBe(
			[
				"# Acme",
				"",
				"> A demo site on tbtop CMS.",
				"",
				"- Phone: +380 44 000 00 00",
				"- Email: hello@example.test",
				"- Address: Kyiv, UA",
				"- GH: https://github.com/DiVotek",
				"",
				"## Pages",
				"- [Welcome](https://site.test): The tbtop CMS demo home page.",
				"- [About us](https://site.test/about-us): Who we are.",
				"",
				"## Categories",
				"- [Blog](https://site.test/blog)",
				"",
				"## Blog",
				"- [First post](https://site.test/blog/first-post): The first article.",
				"",
				"## Optional",
				"- [Sitemap](https://site.test/sitemap.xml): Every indexable URL of the site",
				"- [Українська](https://site.test/uk/llms.txt): This file in another language",
				"",
			].join("\n"),
		);
	});

	test("the editor's summary wins over the default description; notes lose their headings", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.llms_txt.summary", { en: "Bike repair in Kyiv." });
		await cms.site().settings.set("seo.llms_txt.intro", { en: "## Ordering\nCall us.\n\n\n\nPrices\n====\nDaily\n---" });
		const body = (await llms(cms)) ?? "";
		expect(body).toContain("> Bike repair in Kyiv.\n");
		expect(body).toContain("Ordering\nCall us.\n\nPrices\n\nDaily");
		expect(body).not.toContain("## Ordering");
		expect(body).not.toContain("====");
	});

	test("only the ticked types; articles newest first, capped per type; drafts and noindex pages left out", async () => {
		const { cms } = await on();
		const site = cms.site();
		await site.settings.set("seo.llms_txt.types", ["article"]);
		await site.settings.set("seo.llms_txt.per_type", 2);
		const blog = await site.pages.query().select("tbtop_pages.id").where("tbtop_pages.type", "=", "category").executeTakeFirst();
		const article = (name: string, days: number, extra: Record<string, unknown> = {}) =>
			site.pages.create({
				type: "article",
				parentId: blog?.id ?? null,
				status: "published",
				name: { en: name },
				publishedAt: new Date(Date.now() - days * 86_400_000),
				...extra,
			});
		await article("Newest", 0.5);
		await article("Hidden", 0.2, { isIndexable: false });
		await article("Draft", 0.1, { status: "draft" });
		const body = (await llms(cms)) ?? "";
		expect(body).not.toContain("## Pages");
		expect(body).toContain("## Blog\n- [Newest](https://site.test/blog/newest)\n- [First post]");
		expect(body).not.toContain("Hidden");
		expect(body).not.toContain("Draft");
	});

	test("articles under their category in the category order, then those without a category", async () => {
		const section = definePageType({ key: "section", allowedChildren: ["page", "article"] });
		const { cms } = await on({ pageTypes: [section] });
		const site = cms.site();
		const page = (type: string, name: string, extra: Record<string, unknown> = {}) => site.pages.create({ type, status: "published", name: { en: name }, ...extra });
		const guides = await page("category", "Guides", { sortOrder: 5 });
		const services = await page("category", "Services", { sortOrder: 2 });
		const news = await page("section", "News");
		await page("article", "Brake repair", { parentId: services.id });
		await page("article", "Winter storage", { parentId: guides.id });
		await page("article", "We moved", { parentId: news.id });
		expect((await llms(cms)) ?? "").toContain(
			[
				"## Blog",
				"- [First post](https://site.test/blog/first-post): The first article.",
				"",
				"## Services",
				"- [Brake repair](https://site.test/services/brake-repair)",
				"",
				"## Guides",
				"- [Winter storage](https://site.test/guides/winter-storage)",
				"",
				"## Articles",
				"- [We moved](https://site.test/news/we-moved)",
				"",
				"",
			].join("\n"),
		);
	});

	test("host sections follow the page types; relative links are made absolute and stay one link", async () => {
		const files: LlmsTxtSection = { title: () => "Files", links: () => [{ name: "Price [2026]", url: "/files/price list(v2).pdf", notes: "PDF" }] };
		const empty: LlmsTxtSection = { title: () => "Empty", links: async () => [] };
		const { cms } = await on({ llmsTxt: { sections: [files, empty] } });
		const body = (await llms(cms)) ?? "";
		expect(body).toContain("## Files\n- [Price \\[2026\\]](https://site.test/files/price%20list%28v2%29.pdf): PDF\n\n## Optional");
		expect(body).not.toContain("## Empty");
	});
});

describe("llms.txt per locale", () => {
	test("another frontend locale under its prefix, linking back to the default one", async () => {
		const { cms } = await on();
		const res = await cms.handle(get("/uk/llms.txt"));
		expect(res?.headers.get("Content-Type")).toBe("text/plain; charset=utf-8");
		const body = (await res?.text()) ?? "";
		expect(body).toContain("> Демо-сайт на tbtop CMS.");
		expect(body).toContain("](https://site.test/uk/");
		expect(body).toContain("](https://site.test/llms.txt): Цей файл іншою мовою");
	});

	test("the default locale prefix redirects; an unknown prefix is the host's; a disabled file 404s", async () => {
		const { cms } = await on();
		const res = await cms.handle(get("/en/llms.txt"));
		expect(res?.status).toBe(301);
		expect(res?.headers.get("location")).toBe("/llms.txt");
		expect(await cms.handle(get("/de/llms.txt"))).toBeNull();
		expect(await cms.handle(get("/blog/llms.txt"))).toBeNull();
		expect((await (await makeCms()).cms.handle(get("/uk/llms.txt")))?.status).toBe(404);
	});

	test("the HTML answer names its locale's llms.txt, markdown on or off", async () => {
		const { cms } = await on();
		const uk = await cms.loadPage(get("/uk"));
		expect(uk.type === "page" ? uk.headers : null).toEqual({ Link: '<https://site.test/uk/llms.txt>; rel="describedby"; type="text/plain"' });
		const en = await cms.loadPage(get("/"));
		expect(en.type === "page" ? en.headers : null).toEqual({ Link: '<https://site.test/llms.txt>; rel="describedby"; type="text/plain"' });
	});
});

describe("Content-Signal", () => {
	const robots = async (cms: Awaited<ReturnType<typeof makeCms>>["cms"]) => (await (await cms.handle(get("/robots.txt")))?.text()) ?? "";

	test("off until an editor opts in, then the defaults inside the User-agent group", async () => {
		const { cms } = await makeCms();
		expect(await robots(cms)).not.toContain("Content-Signal");
		await cms.site().settings.set("seo.content_signals.enabled", true);
		expect(await robots(cms)).toBe(
			"User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /login\nContent-Signal: search=yes, ai-input=yes, ai-train=no\n\nSitemap: https://site.test/sitemap.xml\n",
		);
	});

	test("per signal, absent while indexing is off, and refused in the custom lines", async () => {
		const { cms } = await makeCms();
		const settings = cms.site().settings;
		await settings.set("seo.content_signals.enabled", true);
		await settings.set("seo.content_signals.ai_input", false);
		await settings.set("seo.content_signals.ai_train", true);
		await settings.set("seo.robots_extra", "Content-Signal: search=no");
		expect(await robots(cms)).toContain("Content-Signal: search=yes, ai-input=no, ai-train=yes\n");
		expect(await robots(cms)).not.toContain("search=no");
		await settings.set("seo.indexing_enabled", false);
		expect(await robots(cms)).not.toContain("Content-Signal");
	});

	test("the For AI tab saves under the keys llms.txt and robots.txt read", async () => {
		const made = await panel();
		const url = "/admin/settings/seo";
		const page = JSON.stringify(await made.page(url));
		expect(page).toContain("seo_llms_txt_types");
		await made.post(
			`${url}/forms/settings_seo`,
			{
				seo_identity_type: "none",
				seo_llms_txt_enabled: true,
				seo_llms_txt_per_type: 20,
				seo_content_signals_enabled: true,
				seo_content_signals_search: false,
				seo_content_signals_ai_input: false,
			},
			url,
		);
		const settings = made.cms.site().settings;
		expect(await settings.get("seo.llms_txt.enabled")).toBe(true);
		expect(Number(await settings.get("seo.llms_txt.per_type"))).toBe(20);
		expect((await (await made.cms.handle(get("/robots.txt")))?.text()) ?? "").toContain("Content-Signal: search=no, ai-input=no, ai-train=no");
		await made.post(`${url}/forms/settings_seo`, { seo_identity_type: "none", seo_robots_extra: "Content-Signal: ai-train=yes" }, url);
		expect(await settings.get("seo.robots_extra")).toBeNull();
	});
});
