import { describe, expect, test } from "bun:test";
import { definePageType, pagesField } from "../src";
import { sitemapChunkXml } from "../src/seo/sitemap";
import { panel } from "./panel";

const en = [{ locale: "en", name: "English", default: true }];

// The recipe: a tag is a category subtype kept off "All", an archive of the articles' `tags` field.
const tag = definePageType({
	key: "tag",
	base: "category",
	listedInAll: false,
	archiveOf: "tags",
	form: () => [],
	resolveFields: async (_input, ctx) => ({ articles: await ctx.referencedByPaged("tags", { from: "article", perPage: 2 }) }),
});
const taggedPost = definePageType({ key: "tagged_post", base: "article", form: () => [pagesField("tags").of("category", "tag").creatable().max(10)] });

const post = (key: string, name: string, publishedAt?: string, status: "draft" | "published" = "published") => ({
	key,
	type: "article",
	subtype: "tagged_post",
	name: { en: name },
	status,
	...(publishedAt === undefined ? {} : { publishedAt }),
});

async function setup(options: { tags?: boolean } = {}) {
	const made = await panel(
		{
			languages: en,
			pages: [
				{
					key: "tags",
					type: "category",
					name: { en: "Tags" },
					children:
						options.tags === false
							? []
							: [
									{ key: "japan", type: "category", subtype: "tag", name: { en: "Japan" } },
									{ key: "empty", type: "category", subtype: "tag", name: { en: "Empty" } },
								],
				},
				{
					key: "blog",
					type: "category",
					name: { en: "Blog" },
					children: [
						post("ramen", "Ramen", "2026-10-01T00:00:00Z"),
						post("sushi", "Sushi", "2026-10-03T00:00:00Z"),
						post("onsen", "Onsen", "2026-10-02T00:00:00Z"),
						post("draft", "Draft", undefined, "draft"),
						post("hidden", "Hidden", undefined, "draft"),
					],
				},
			],
		},
		{ pageTypes: [tag, taggedPost] },
	);
	const pages = made.cms.site().pages;
	const tagWith = async (key: string, tagKey: string) => {
		const page = await pages.find(made.ids[key] as number);
		await pages.update(page?.id as number, { fields: { tags: [String(made.ids[tagKey])] } }, { lockVersion: page?.lockVersion });
	};
	if (options.tags !== false) {
		for (const key of ["ramen", "sushi", "onsen", "draft"]) {
			await tagWith(key, "japan");
		}
		await tagWith("hidden", "empty");
	}
	return made;
}

const load = async (cms: Awaited<ReturnType<typeof setup>>["cms"], path: string) => cms.loadPage(new Request(`https://site.test${path}`));

type Archive = { articles: { items: { name: string }[]; pagination: { page: number; pages: number; total: number; perPage: number } } };

describe("tags as a category subtype (A-thin)", () => {
	test("a tag lists its live articles newest first, a page at a time, each page its own canonical", async () => {
		const { cms } = await setup();
		const first = await load(cms, "/tags/japan");
		const second = await load(cms, "/tags/japan?page=2");
		if (first.type !== "page" || second.type !== "page") throw new Error("not a page");

		const one = first.props.page.fields as Archive;
		const two = second.props.page.fields as Archive;
		expect(one.articles.items.map((ref) => ref.name)).toEqual(["Sushi", "Onsen"]);
		expect(one.articles.pagination).toEqual({ page: 1, pages: 2, total: 3, perPage: 2 });
		expect(first.props.seo.canonical).toBe("https://site.test/tags/japan");
		expect(two.articles.items.map((ref) => ref.name)).toEqual(["Ramen"]);
		expect(second.props.seo.canonical).toBe("https://site.test/tags/japan?page=2");
	});

	test("past the last page is a 404; a malformed page number is page 1", async () => {
		const { cms } = await setup();
		expect((await load(cms, "/tags/japan?page=3")).status).toBe(404);
		const malformed = await load(cms, "/tags/japan?page=abc");
		if (malformed.type !== "page") throw new Error(malformed.type);
		expect((malformed.props.page.fields as Archive).articles.pagination.page).toBe(1);
	});

	test("a tag nothing live is tagged with is noindex and out of the sitemap", async () => {
		const { cms } = await setup();
		const empty = await load(cms, "/tags/empty");
		const japan = await load(cms, "/tags/japan");
		if (empty.type !== "page" || japan.type !== "page") throw new Error("not a page");
		expect(empty.props.seo.noindex).toBe(true);
		expect(empty.headers).toEqual({ "X-Robots-Tag": "noindex" });
		expect(japan.props.seo.noindex).toBe(false);

		const xml = await sitemapChunkXml(cms.site(), 1);
		expect(xml).toContain("https://site.test/tags/japan");
		expect(xml).not.toContain("https://site.test/tags/empty");
		expect(xml).toContain("https://site.test/blog/ramen");
	});

	test("the select creates a published tag next to the other tags", async () => {
		const { cms, ids, post: send } = await setup();
		const url = `/admin/pages/${ids.ramen}/edit`;
		const res = await send(`${url}/select-create/field_tagged_post__tags`, { name: "Street food" }, url);
		expect(res.status).toBe(200);
		const created = (await res.json()) as { value: string; label: string };
		const page = await cms.site().pages.find(Number(created.value));

		expect(created.label).toBe("Street food");
		expect([page?.type, page?.subtype, page?.parentId, page?.status]).toEqual(["category", "tag", ids.tags, "published"]);
		expect((await load(cms, "/tags/street-food")).status).toBe(200);
	});

	test("the select refuses the first tag: the first one shows where tags live", async () => {
		const { cms, ids, post: send } = await setup({ tags: false });
		const url = `/admin/pages/${ids.ramen}/edit`;
		const res = await send(`${url}/select-create/field_tagged_post__tags`, { name: "Street food" }, url);
		expect(res.status).toBe(422);
		expect(((await res.json()) as { errors: Record<string, string[]> }).errors.name).toHaveLength(1);
		expect(await cms.site().pages.query().select("id").where("subtype", "=", "tag").execute()).toEqual([]);
	});

	test("tags stay off the Pages \"All\" tab and get a tab of their own", async () => {
		const { ids, rows } = await setup();
		const all = (await rows("pages", "/admin/pages", "all")).map((row) => Number(row.id));
		const tags = (await rows("pages", "/admin/pages", "tag")).map((row) => Number(row.id));
		expect(all).toContain(ids.ramen as number);
		expect(all).not.toContain(ids.japan as number);
		expect(tags.sort()).toEqual([ids.japan as number, ids.empty as number].sort());
	});

	test("a category's children page the same way", async () => {
		const { cms, ids } = await setup();
		const site = cms.site();
		const page = await site.childrenLinksPaged(await site.pages.find(ids.blog as number), { from: "article", perPage: 2, page: 2 });
		expect(page.items.map((ref) => ref.name)).toEqual(["Ramen"]);
		expect(page.pagination).toEqual({ page: 2, pages: 2, total: 3, perPage: 2 });
	});
});
