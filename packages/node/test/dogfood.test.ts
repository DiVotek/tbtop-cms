import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { definePageType, demoFixture, type Fixture, isFreshSite, pagesField, type SchemaTypeDef, seedFixtures } from "../src";
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
		form: (s) => [pagesField("items").label("Items").of("article")],
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
