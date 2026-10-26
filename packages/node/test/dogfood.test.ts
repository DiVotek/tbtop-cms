import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { definePageType, demoFixture, type Fixture, type SchemaTypeDef } from "../src";
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
