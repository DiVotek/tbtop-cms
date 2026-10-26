import { describe, expect, test } from "bun:test";
import type { S } from "@tbtop/admin";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, defineBlocks, definePageType, implementBlocks } from "../src";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];

describe("slugs", () => {
	test("a taken path gets a numeric suffix; renaming a parent's slug moves its children", async () => {
		const { cms } = await makeCms({ languages: en, pages: [{ key: "a", type: "category", name: { en: "News" }, children: [{ type: "article", name: { en: "Item" } }] }] });
		const site = cms.site();
		const second = await site.pages.create({ type: "page", name: { en: "News" } });
		expect(await site.pages.slugs.allFor(second.id)).toEqual({ en: "news-2" });

		const parent = await site.pages.query().selectAll().where("type", "=", "category").executeTakeFirstOrThrow();
		const page = await site.pages.find(Number(parent.id));
		await site.pages.update(Number(parent.id), {}, { slugs: { en: "Updates & more" }, lockVersion: page?.lockVersion });
		expect((await cms.loadPage(get("/updates-more/item"))).type).toBe("page");
		expect((await cms.loadPage(get("/news/item"))).type).toBe("redirect");
	});

	test("a stale lock version is a conflict", async () => {
		const { cms, ids } = await makeCms({ languages: en, pages: [{ key: "p", name: { en: "P" } }] });
		const site = cms.site();
		await site.pages.update(ids.p as number, { name: { en: "Q" } }, { lockVersion: 0 });
		await expect(site.pages.update(ids.p as number, { name: { en: "R" } }, { lockVersion: 0 })).rejects.toThrow("changed by someone else");
	});
});

describe("blocks", () => {
	const fixture = { languages: en, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

	async function render(content: unknown[], blocksConfig = implementBlocks(cmsBlocks, cmsBlockImpls), strictBlocks?: boolean) {
		const { cms, ids } = await makeCms(fixture, { blocks: blocksConfig, ...(strictBlocks === undefined ? {} : { strictBlocks }) });
		await cms.site().pages.update(ids.home ?? 1, { content: content as never });
		const result = await cms.loadPage(get("/"));
		if (result.type !== "page") throw new Error(result.type);
		return result.props.render;
	}

	test("rows render in sort order; inactive, unknown and broken shared rows are skipped", async () => {
		const spec = await render([
			{ id: "b", kind: "Hero", sort: 2, data: { title: { en: "Second" } } },
			{ id: "a", kind: "Hero", sort: 1, data: { title: { en: "First" } } },
			{ id: "off", kind: "Hero", active: false, data: { title: { en: "Off" } } },
			{ id: "unknown", kind: "Nope", data: {} },
			{ id: "dangling", kind: "Hero", shared: 999 },
		]);
		expect(spec.elements.$root?.children).toEqual(["a", "b"]);
		expect(spec.elements.a?.props.title).toBe("First");
	});

	test("props that break the catalog schema are dropped, not shipped (strictBlocks off, as in production)", async () => {
		const catalog = defineBlocks({ Quote: { props: z.object({ text: z.string() }) } });
		const blocks = implementBlocks(catalog, {
			Quote: { form: (s) => [s.text("text").translatable()], resolve: (input) => ({ text: (input.text as string) ?? null }) as never },
		});
		const spec = await render(
			[
				{ id: "ok", kind: "Quote", data: { text: { en: "Hi" } } },
				{ id: "bad", kind: "Quote", data: {} },
			],
			blocks,
			false,
		);
		expect(spec.elements.$root?.children).toEqual(["ok"]);
	});

	test("a valid block ships its parsed props, so a schema default fills what the resolver left out", async () => {
		const catalog = defineBlocks({ Quote: { props: z.object({ text: z.string(), surface: z.boolean().default(false) }) } });
		const blocks = implementBlocks(catalog, { Quote: { form: (s) => [s.text("text").translatable(), s.text("note")] } });
		const spec = await render([{ id: "q", kind: "Quote", data: { text: { en: "Hi" }, note: "kept" } }], blocks, true);
		// A key the schema does not declare is not stripped.
		expect(spec.elements.q?.props).toEqual({ text: "Hi", note: "kept", surface: false });
	});

	test("a defaulted key behind a transform is left to the browser, so the transform runs once", async () => {
		const catalog = defineBlocks({ Quote: { props: z.object({ text: z.string(), tone: z.string().default("calm").transform((tone) => `tone-${tone}`) }) } });
		const blocks = implementBlocks(catalog, { Quote: { form: (s) => [s.text("text").translatable()] } });
		const spec = await render([{ id: "q", kind: "Quote", data: { text: { en: "Hi" } } }], blocks, true);
		expect(spec.elements.q?.props).toEqual({ text: "Hi" });
	});

	test("a filled default ships only when the browser's parse of it gives it back", async () => {
		const catalog = defineBlocks({
			Quote: {
				props: z.object({
					text: z.string(),
					tone: z.string().transform((tone) => `tone-${tone}`).default("calm"),
					size: z.string().transform((value) => value.length).prefault("ab"),
					at: z.date().default(() => new Date(0)),
					surface: z.boolean().default(false),
				}),
			},
		});
		const blocks = implementBlocks(catalog, { Quote: { form: (s) => [s.text("text").translatable()] } });
		const spec = await render([{ id: "q", kind: "Quote", data: { text: { en: "Hi" } } }], blocks, true);
		// Only the plain default: the others would change or fail when the browser parses them again.
		expect(spec.elements.q?.props).toEqual({ text: "Hi", surface: false });
	});

	test("a whole-object transform ships nothing it computed", async () => {
		const catalog = defineBlocks({ Quote: { props: z.object({ text: z.string() }).transform((props) => ({ ...props, shout: props.text.toUpperCase() })) } });
		const blocks = implementBlocks(catalog, { Quote: { form: (s) => [s.text("text").translatable()] } });
		const spec = await render([{ id: "q", kind: "Quote", data: { text: { en: "Hi" } } }], blocks, true);
		expect(spec.elements.q?.props).toEqual({ text: "Hi" });
	});

	test("a block resolver reads site settings through ctx.site", async () => {
		const catalog = defineBlocks({ Footer: { props: z.object({ phone: z.string().nullable() }) } });
		const blocks = implementBlocks(catalog, {
			Footer: { form: () => [], resolve: async (_input, ctx) => ({ phone: ((await ctx.site.settings.get("site.contacts.phones")) as { number: string }[] | null)?.[0]?.number ?? null }) },
		});
		const { cms, ids } = await makeCms(fixture, { blocks });
		await cms.site().settings.set("site.contacts.phones", [{ number: "+380 44 000 00 00" }]);
		await cms.site().pages.update(ids.home ?? 1, { content: [{ id: "f", kind: "Footer", data: {} }] as never });
		const result = await cms.loadPage(get("/"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.render.elements.f?.props).toEqual({ phone: "+380 44 000 00 00" });
	});

	test("a key the resolver set ships as resolved, so a transform does not run twice", async () => {
		const catalog = defineBlocks({ Quote: { props: z.object({ text: z.string().transform((text) => `«${text}»`) }) } });
		const blocks = implementBlocks(catalog, { Quote: { form: (s) => [s.text("text").translatable()] } });
		const spec = await render([{ id: "q", kind: "Quote", data: { text: { en: "Hi" } } }], blocks, true);
		expect(spec.elements.q?.props).toEqual({ text: "Hi" });
	});

	test("the default resolver maps fields by kind: richtext to HTML, numbers, booleans, repeater rows", async () => {
		const catalog = defineBlocks({
			Stats: {
				props: z.object({
					intro: z.string().nullable(),
					count: z.number().nullable(),
					featured: z.boolean(),
					items: z.array(z.object({ label: z.string().nullable() })),
				}),
			},
		});
		const blocks = implementBlocks(catalog, {
			Stats: {
				form: (s) => [
					s.richtext("intro").translatable(),
					s.number("count"),
					s.boolean("featured"),
					s.repeater("items").fields([s.text("label").translatable()]),
				],
			},
		});
		const spec = await render(
			[
				{
					id: "s",
					kind: "Stats",
					data: {
						intro: { en: { root: { children: [{ type: "paragraph", children: [{ type: "text", text: "Bold", format: 1 }] }] } } },
						count: "42",
						featured: "1",
						items: [{ label: { en: "One" } }, { label: { en: "  " } }],
					},
				},
			],
			blocks,
		);
		expect(spec.elements.s?.props).toEqual({ intro: "<p><strong>Bold</strong></p>", count: 42, featured: true, items: [{ label: "One" }] });
	});

	test("an unknown catalog kind without an implementation fails at registration", () => {
		const catalog = defineBlocks({ Lonely: { props: z.object({}) } });
		expect(() => implementBlocks(catalog, {} as never)).toThrow("Block [Lonely] has no implementation.");
	});
});

describe("fixture bodies", () => {
	test("plain text becomes paragraphs; a Lexical document is kept, headings and links included", async () => {
		const doc = {
			root: {
				type: "root",
				children: [
					{ type: "heading", tag: "h2", children: [{ type: "text", text: "Why us", format: 0 }] },
					{ type: "paragraph", children: [{ type: "link", url: "https://example.com", children: [{ type: "text", text: "Read more", format: 0 }] }] },
				],
			},
		};
		const { cms } = await makeCms({
			languages: en,
			pages: [
				{ key: "home", system: true, name: { en: "Home" }, body: { en: doc } },
				{ key: "plain", name: { en: "Plain" }, slug: { en: "plain" }, body: { en: "One\n\nTwo" } },
			],
		});
		const body = async (path: string) => {
			const result = await cms.loadPage(get(path));
			return result.type === "page" ? result.props.page.body : result.type;
		};
		const home = await body("/");
		expect(home).toContain("<h2");
		expect(home).toContain("Why us");
		expect(home).toContain('href="https://example.com"');
		expect(await body("/plain")).toBe("<p>One</p><p>Two</p>");
	});
});

describe("extending a built-in page type", () => {
	const fixture = { languages: en, pages: [{ key: "home", system: true, name: { en: "Home" } }] };
	const tagline = definePageType({ key: "page", form: (s) => [s.text("tagline").translatable()], schema: z.object({ tagline: z.string().nullable() }) });

	test("definePageType with a built-in key adds its fields and keeps the tree rules", async () => {
		const category = definePageType({ key: "category", label: "Section", form: (s) => [s.text("icon")] });
		const { cms, ids } = await makeCms(fixture, { pageTypes: [tagline, category] });
		expect(cms.pageTypes.get("category")).toMatchObject({ label: "Section", maxDepth: 3, allowedChildren: ["category", "article"], schemaTypes: ["CollectionPage"] });
		await cms.site().pages.update(ids.home ?? 1, { fields: { tagline: { en: "We build" } } });
		const result = await cms.loadPage(get("/"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.page.fields).toEqual({ tagline: "We build" });
	});

	test("a key the host def leaves undefined keeps the built-in's value", async () => {
		const category = { key: "category", allowedChildren: undefined, schemaTypes: undefined, form: (s: S) => [s.text("icon")] };
		const { cms } = await makeCms(fixture, { pageTypes: [category] });
		expect(cms.pageTypes.get("category")).toMatchObject({ allowedChildren: ["category", "article"], schemaTypes: ["CollectionPage"] });
	});

	test("its tree rules stay the package's; a second extension of the same key is refused", async () => {
		await expect(makeCms(fixture, { pageTypes: [definePageType({ key: "article", requiresParent: false })] })).rejects.toThrow("Page type [article] is built in");
		await expect(makeCms(fixture, { pageTypes: [tagline, tagline] })).rejects.toThrow("Page type [page] is already registered.");
	});
});

describe("createCms timezone", () => {
	test("a name Intl does not know is refused up front", async () => {
		await expect(makeCms(null, { timezone: "Europe/Atlantis" })).rejects.toThrow('timezone "Europe/Atlantis" is not a valid IANA name');
	});
});
