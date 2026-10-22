import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, defineBlocks, implementBlocks } from "../src";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];

describe("slugs", () => {
	test("a taken path gets a numeric suffix; renaming a parent's slug moves its children", async () => {
		const { cms } = await makeCms({ languages: en, pages: [{ key: "a", type: "category", name: { en: "News" }, children: [{ name: { en: "Item" } }] }] });
		const site = cms.site();
		const second = await site.pages.create({ type: "page", name: { en: "News" } });
		expect(await site.pages.slugs.allFor(second.id)).toEqual({ en: "news-2" });

		const parent = await site.pages.query().selectAll().where("type", "=", "category").executeTakeFirstOrThrow();
		const page = await site.pages.find(Number(parent.id));
		await site.pages.update(Number(parent.id), {}, { slugs: { en: "Updates & more" }, lockVersion: page?.lockVersion });
		expect((await cms.loadPage(get("/updates-more/item"))).type).toBe("page");
		expect((await cms.loadPage(get("/news/item"))).type).toBe("notFound");
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

	async function render(content: unknown[], blocksConfig = implementBlocks(cmsBlocks, cmsBlockImpls)) {
		const { cms, ids } = await makeCms(fixture, { blocks: blocksConfig });
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

	test("props that break the catalog schema are dropped, not shipped", async () => {
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
		);
		expect(spec.elements.$root?.children).toEqual(["ok"]);
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
