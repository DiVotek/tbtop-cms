import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, demoFixture, type Fixture, implementBlocks, seedFixtures } from "../src";
import { acceptsMarkdown, suffixPath } from "../src/seo/markdown/page";
import { richtextMarkdown } from "../src/seo/markdown/richtext";
import { get, makeCms } from "./support";

async function on(config: Parameters<typeof makeCms>[1] = {}, fixture: Fixture | null = demoFixture) {
	const made = await makeCms(fixture, config);
	await made.cms.site().settings.set("seo.markdown.enabled", true);
	return made;
}

const md = async (cms: Awaited<ReturnType<typeof makeCms>>["cms"], path: string, init?: RequestInit) => cms.handle(get(path, init));

describe("page markdown", () => {
	test("is not served until an editor turns it on", async () => {
		const { cms } = await makeCms();
		expect(await md(cms, "/about-us.md")).toBeNull();
		expect(await md(cms, "/about-us", { headers: { Accept: "text/markdown" } })).toBeNull();
	});

	test("is not served while indexing is off, nor with markdown.enabled: false", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.indexing_enabled", false);
		expect(await md(cms, "/about-us.md")).toBeNull();
		const off = (await on({ markdown: { enabled: false } })).cms;
		expect(await md(off, "/about-us.md")).toBeNull();
	});

	test("the page at its path plus .md: frontmatter, heading, summary, body, about, JSON-LD", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.llms_txt.intro", { en: "We ship daily." });
		const res = await md(cms, "/about-us.md");
		expect(res?.status).toBe(200);
		expect(res?.headers.get("Content-Type")).toBe("text/markdown; charset=utf-8");
		expect(res?.headers.get("Vary")).toBe("Accept");
		expect(res?.headers.get("Link")).toBe('<https://site.test/about-us>; rel="canonical"');
		expect(res?.headers.get("X-Robots-Tag")).toBeNull();
		const body = (await res?.text()) ?? "";
		expect(body).toStartWith('---\ntitle: "About us — tbtop demo"\ndescription: "Who we are."\nurl: "https://site.test/about-us"\nlang: "en"\nalternates:\n  "uk": "https://site.test/uk/');
		expect(body).toContain("---\n\n# About us\n\nWho we are.\n\nWe build small, fast sites.\n\nThis page has a body and no blocks.\n\n## About tbtop demo\n\nWe ship daily.\n\n- Phone: +380 44 000 00 00");
		expect(body).toContain('```json\n[\n    {\n        "@context": "https://schema.org",');
		expect(body).toContain('"@type": "AboutPage"');
	});

	test("Accept: text/markdown on the page URL; a browser gets the host's HTML", async () => {
		const { cms } = await on();
		const res = await md(cms, "/about-us", { headers: { Accept: "text/markdown, text/html" } });
		expect(await res?.text()).toContain("# About us");
		expect(await md(cms, "/about-us", { headers: { Accept: "text/html,application/xhtml+xml,*/*;q=0.8" } })).toBeNull();
		expect(await md(cms, "/about-us", { headers: { Accept: "text/markdown", "X-Inertia": "true" } })).toBeNull();
		expect(await md(cms, "/about-us.md", { method: "POST" })).toBeNull();
	});

	test("the home page at /index.md, with its blocks", async () => {
		const { cms } = await on();
		const body = (await (await md(cms, "/index.md"))?.text()) ?? "";
		expect(body).toContain("# Welcome\n\nThe tbtop CMS demo home page.\n\n## Welcome to tbtop CMS\n\nThis hero block is rendered through json-render.\n\n[Read the blog](https://site.test/blog)");
		expect(body).toContain("## Write to us\n\nThis page has a contact form.");
	});

	test("another locale under its prefix", async () => {
		const { cms } = await on();
		const body = (await (await md(cms, "/uk/index.md"))?.text()) ?? "";
		expect(body).toContain('lang: "uk"');
		expect(body).toContain("## Вітаємо в tbtop CMS");
		expect(body).toContain("На цій сторінці є форма зворотного звʼязку.");
	});

	test("a category lists its live children with .md links; drafts and missing pages are left to the host", async () => {
		const { cms } = await on();
		const body = (await (await md(cms, "/blog.md"))?.text()) ?? "";
		expect(body).toContain("## In this section\n\n- [First post](https://site.test/blog/first-post.md): The first article.");
		expect(body).not.toContain("Coming soon");
		expect(await md(cms, "/work-in-progress.md")).toBeNull();
		expect(await md(cms, "/missing.md")).toBeNull();
	});

	test("a redirect answers the .md path as the HTML one", async () => {
		const { cms } = await on();
		const res = await md(cms, "/old-about.md");
		expect(res?.status).toBe(301);
		expect(res?.headers.get("location")).toBe("/about-us");
	});

	test("noindex pages are tagged; content signals ride along when on", async () => {
		const { cms } = await on();
		const site = cms.site();
		await site.settings.set("seo.content_signals.enabled", true);
		const about = await site.pages.query().select("tbtop_pages.id").where("tbtop_pages.is_system", "=", 0).where("tbtop_pages.type", "=", "page").executeTakeFirstOrThrow();
		await site.pages.update(about.id, { isIndexable: false });
		const res = await md(cms, "/about-us.md");
		expect(res?.headers.get("X-Robots-Tag")).toBe("noindex");
		expect(res?.headers.get("Content-Signal")).toBe("search=yes, ai-input=yes, ai-train=no");
	});

	test("the HTML answer names its markdown twin and llms.txt", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.llms_txt.enabled", true);
		const result = await cms.loadPage(get("/about-us"));
		expect(result.type === "page" ? result.headers : null).toEqual({
			Vary: "Accept",
			Link: '<https://site.test/about-us.md>; rel="alternate"; type="text/markdown", <https://site.test/llms.txt>; rel="describedby"; type="text/plain"',
		});
		const off = (await makeCms()).cms;
		const plain = await off.loadPage(get("/about-us"));
		expect(plain.type === "page" ? plain.headers : null).toEqual({});
	});

	test("llms.txt links the .md versions", async () => {
		const { cms } = await on();
		await cms.site().settings.set("seo.llms_txt.enabled", true);
		expect(await (await cms.handle(get("/llms.txt")))?.text()).toContain("- [About us](https://site.test/about-us.md): Who we are.");
	});

	test("a block without toMarkdown falls back to its translatable text; '' hides it; a throw skips it", async () => {
		const loose = { props: z.looseObject({}) };
		const blocks = implementBlocks(
			{ ...cmsBlocks, Notes: loose, Hidden: loose, Broken: loose },
			{
				...cmsBlockImpls,
				Notes: { form: (s) => [s.text("title").translatable(), s.text("code"), s.repeater("rows").fields([s.text("label").translatable()])] },
				Hidden: { form: (s) => [s.text("title").translatable()], toMarkdown: () => "" },
				Broken: {
					form: (s) => [s.text("title").translatable()],
					toMarkdown: () => {
						throw new Error("boom");
					},
				},
			},
		);
		const { cms } = await on({ blocks }, null);
		await seedFixtures(cms, {
			languages: [{ locale: "en", name: "English", default: true }],
			pages: [
				{
					key: "notes",
					name: { en: "Notes" },
					blocks: [
						{ kind: "Notes", data: { title: { en: "Notes [beta]" }, code: "SKU-1", rows: [{ label: { en: "First" } }, { label: { en: "Second" } }] } },
						{ kind: "Hidden", data: { title: { en: "Invisible" } } },
						{ kind: "Broken", data: { title: { en: "Gone" } } },
					],
				},
			],
		});
		await cms.site().settings.set("seo.markdown.enabled", true);
		const body = (await (await md(cms, "/notes.md"))?.text()) ?? "";
		expect(body).toContain("Notes \\[beta\\]\n\nFirst\n\nSecond");
		expect(body).not.toContain("SKU-1");
		expect(body).not.toContain("Invisible");
		expect(body).not.toContain("Gone");
	});

	test("a page type's toMarkdown that throws is left out; ctx.link drops an unsafe URL", async () => {
		const { cms } = await on(
			{
				pageTypes: [
					{ key: "broken", toMarkdown: () => Promise.reject(new Error("boom")) },
					{ key: "linked", toMarkdown: (_page, ctx) => `${ctx.link("Bad", "javascript:alert(1)")} ${ctx.link("Good", "/ok")}` },
				],
			},
			null,
		);
		await seedFixtures(cms, {
			languages: [{ locale: "en", name: "English", default: true }],
			pages: [
				{ key: "b", type: "broken", name: { en: "Broken" } },
				{ key: "l", type: "linked", name: { en: "Linked" } },
			],
		});
		await cms.site().settings.set("seo.markdown.enabled", true);
		expect((await md(cms, "/broken.md"))?.status).toBe(200);
		expect(await (await md(cms, "/linked.md"))?.text()).toContain("Bad [Good](https://site.test/ok)");
	});

	test("a page type's toMarkdown is added after the blocks", async () => {
		const { cms } = await on({ pageTypes: [{ key: "service", toMarkdown: (_page, ctx) => `${ctx.heading("Price")}\n\n${ctx.text("100 UAH")}` }] }, null);
		await seedFixtures(cms, { languages: [{ locale: "en", name: "English", default: true }], pages: [{ key: "repair", type: "service", name: { en: "Repair" } }] });
		await cms.site().settings.set("seo.markdown.enabled", true);
		expect(await (await md(cms, "/repair.md"))?.text()).toContain("# Repair\n\n## Price\n\n100 UAH");
	});
});

describe("markdown requests", () => {
	test.each([
		["text/markdown", true],
		["text/markdown, text/html", true],
		["text/html;q=0.8, text/markdown", true],
		["text/html, text/markdown", false],
		["text/markdown;q=0.5, text/html", false],
		["*/*", false],
		["text/markdown;q=0", false],
		["text/markdown;q=0, text/markdown", true],
	])("Accept %s → %p", (accept, expected) => {
		expect(acceptsMarkdown(get("/", { headers: { Accept: accept } }))).toBe(expected);
	});

	test("a .md path maps to the page path", () => {
		expect(suffixPath("about.md")).toBe("about");
		expect(suffixPath("blog/post.md")).toBe("blog/post");
		expect(suffixPath("index.md")).toBe("");
		expect(suffixPath("about")).toBeNull();
		expect(suffixPath(".md")).toBeNull();
	});
});

describe("richtext markdown", () => {
	const render = (value: unknown) => richtextMarkdown(value, (url) => (url.startsWith("/") ? `https://site.test${url}` : url));

	test("headings from H2, inline formats, links and lists", () => {
		expect(render('<h1>Title</h1><p><strong>Bold</strong>, <em>it</em> and <code>a`b</code> <a href="/prices">prices</a> <a href="javascript:alert(1)">bad</a></p>')).toBe(
			"## Title\n\n**Bold**, _it_ and ``a`b`` [prices](https://site.test/prices) bad",
		);
		expect(render("<ol><li>One<ul><li>Inner</li></ul></li><li>Two</li></ol>")).toBe("1. One\n   - Inner\n2. Two");
	});

	test("escapes text that would become markup; reads a Lexical state", () => {
		expect(render("<p># not a heading *x*</p><p>1. not a list</p>")).toBe("\\# not a heading \\*x\\*\n\n1\\. not a list");
		expect(render({ root: { children: [{ type: "quote", children: [{ type: "text", text: "Said", format: 0 }] }] } })).toBe("> Said");
		expect(render("<pre>a ``` b</pre>")).toBe("````\na ``` b\n````");
	});
});
