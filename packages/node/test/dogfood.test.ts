import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, definePageType, demoFixture, type Fixture, implementBlocks, isFreshSite, pageFromRow, pagesField, whereLive, type SchemaTypeDef, seedFixtures } from "../src";
import { get, makeCms } from "./support";

// Findings from the dogfood sites (Golfstrimfish, Lumen Studio) that need no new API.

describe("Dogfood: a host JSON-LD type for a catalog subtype", () => {
	// The README's Product example: a host schema type named by a subtype's schemaTypes.
	const productSchema: SchemaTypeDef = {
		key: "Product",
		label: "Product",
		build: (ctx) => [
			{
				"@context": "https://schema.org",
				"@type": "Product",
				name: ctx.name,
				url: ctx.url,
				offers: { "@type": "Offer", price: ctx.page.fields?.price, priceCurrency: "UAH" },
			},
		],
	};
	const productType = definePageType({
		key: "product",
		base: "article",
		schemaTypes: ["Product"],
		schema: z.object({ price: z.number().nullable() }),
		form: (s) => [s.number("price").label("Price")],
	});
	const fixture: Fixture = {
		...demoFixture,
		pages: demoFixture.pages?.map((page) =>
			page.key === "blog"
				? { ...page, children: [...(page.children ?? []), { type: "article", subtype: "product", name: { en: "Bream steak" }, slug: { en: "bream" }, fields: { price: 120 } }] }
				: page,
		),
	};

	test("the product page's JSON-LD is the host's Product with its price", async () => {
		const { cms } = await makeCms(fixture, { pageTypes: [productType], schemaTypes: [productSchema] });
		const result = await cms.loadPage(get("/blog/bream"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.seo.jsonLd).toContainEqual({
			"@context": "https://schema.org",
			"@type": "Product",
			name: "Bream steak",
			url: "https://site.test/blog/bream",
			offers: { "@type": "Offer", price: 120, priceCurrency: "UAH" },
		});
	});
});

describe("Dogfood: a host seeds step by step with real ids", () => {
	const shelfType = definePageType({
		key: "shelf",
		schema: z.object({ items: z.array(z.object({ id: z.string(), name: z.string(), url: z.string() })) }),
		form: () => [pagesField("items").label("Items").of("article")],
	});

	test("pages created first are linked by id in a later step; the site is no longer fresh", async () => {
		const { cms } = await makeCms(null, { pageTypes: [shelfType] });
		expect(await isFreshSite(cms)).toBe(true);
		const pages = cms.site().pages;
		const catalog = await pages.create({ type: "category", name: { en: "Catalog" }, status: "published" }, { slugs: { en: "catalog" } });
		const bream = await pages.create({ type: "article", parentId: catalog.id, name: { en: "Bream steak" }, status: "published" }, { slugs: { en: "bream" } });
		const shelf = await pages.create({ type: "shelf", name: { en: "Shelf" }, status: "published" }, { slugs: { en: "shelf" } });
		await pages.update(shelf.id, { fields: { items: [String(bream.id)] } });

		const result = await cms.loadPage(get("/shelf"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.page.fields).toEqual({ items: [{ id: String(bream.id), name: "Bream steak", url: "/catalog/bream" }] });
		expect(await isFreshSite(cms)).toBe(false);
		expect(await seedFixtures(cms, demoFixture)).toEqual({});
	});
});

describe("Dogfood: a block reads the fields of the pages it lists", () => {
	const productType = definePageType({
		key: "product",
		base: "article",
		schema: z.object({ price: z.number().nullable() }),
		form: (s) => [s.number("price").label("Price")],
	});
	// A page type whose resolver lists its picks with their fields: two such pages picking each other must not recurse.
	const pairType = definePageType({
		key: "pair",
		form: () => [pagesField("other").label("Other").of("pair")],
		resolveFields: async (input, ctx) => ({ other: await ctx.pages(input.other, { fields: true }) }),
	});
	const grid = z.object({ products: z.array(z.object({ id: z.string(), name: z.string(), url: z.string(), price: z.number().nullable() })) });
	const blocks = implementBlocks(
		{ ...cmsBlocks, ProductGrid: { props: grid } },
		{
			...cmsBlockImpls,
			ProductGrid: {
				form: () => [pagesField("products").label("Products").of("article", "product")],
				resolve: async (input, ctx) => ({
					products: (await ctx.pages(input.products, { fields: true })).map(({ fields, ...ref }) => ({
						...ref,
						price: typeof fields.price === "number" ? fields.price : null,
					})),
				}),
			},
		},
	);

	test("fields: true adds each listed page's resolved fields; without it the ref stays { id, name, url }", async () => {
		const { cms } = await makeCms(null, { pageTypes: [productType, pairType], blocks });
		const pages = cms.site().pages;
		const catalog = await pages.create({ type: "category", name: { en: "Catalog" }, status: "published" }, { slugs: { en: "catalog" } });
		const bream = await pages.create({ type: "article", subtype: "product", parentId: catalog.id, name: { en: "Bream" }, status: "published", fields: { price: 120 } }, { slugs: { en: "bream" } });
		const draft = await pages.create({ type: "article", subtype: "product", parentId: catalog.id, name: { en: "Carp" }, fields: { price: 90 } }, { slugs: { en: "carp" } });
		await pages.update(catalog.id, { content: [{ id: "grid", kind: "ProductGrid", active: true, sort: 0, data: { products: [String(bream.id), String(draft.id)] } }] });

		const result = await cms.loadPage(get("/catalog"));
		if (result.type !== "page") throw new Error(result.type);
		expect(JSON.stringify(result.props.render)).toContain(JSON.stringify({ products: [{ id: String(bream.id), name: "Bream", url: "/catalog/bream", price: 120 }] }));
	});

	test("pages that pick each other resolve one level deep", async () => {
		const { cms } = await makeCms(null, { pageTypes: [productType, pairType], blocks });
		const pages = cms.site().pages;
		const a = await pages.create({ type: "pair", name: { en: "A" }, status: "published" }, { slugs: { en: "a" } });
		const b = await pages.create({ type: "pair", name: { en: "B" }, status: "published", fields: { other: [String(a.id)] } }, { slugs: { en: "b" } });
		await pages.update(a.id, { fields: { other: [String(b.id)] } });

		const result = await cms.loadPage(get("/a"));
		if (result.type !== "page") throw new Error(result.type);
		// B's own pick of A comes back as a ref with empty fields: no third level.
		expect(result.props.page.fields).toEqual({
			other: [{ id: String(b.id), name: "B", url: "/b", fields: { other: [{ id: String(a.id), name: "A", url: "/a", fields: {} }] } }],
		});
	});
});

describe("Dogfood: a host lists a category's live children a page at a time", () => {
	test("whereLive and pageFromRow page through the children the frontend would show", async () => {
		const { cms, ids } = await makeCms(demoFixture);
		const site = cms.site(get("/blog?page=1"));
		const blog = ids.blog as number;
		const children = site.pages.query().$call(whereLive).where("parent_id", "=", blog);
		const { total } = await children.select((eb) => eb.fn.countAll<number>().as("total")).executeTakeFirstOrThrow();
		const posts = (await children.selectAll().orderBy("published_at", "desc").orderBy("id", "desc").limit(10).offset(0).execute()).map(pageFromRow);
		const cards = await Promise.all(posts.map(async (post) => ({ id: post.id, name: post.name.en, url: await site.pageUrl(post, "en") })));

		// The 2037 post is scheduled, so it is neither counted nor listed.
		expect(Number(total)).toBe(1);
		expect(cards).toEqual([{ id: ids["first-post"] as number, name: "First post", url: "/blog/first-post" }]);
	});
});
