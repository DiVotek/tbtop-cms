import { z } from "zod";
import { baseNode, identityId } from "../../../src";
import { hero } from "../kit/hero";
import { organization, serviceNode } from "../shared/reference";
import { Promo } from "./blocks";
import { defineConfig } from "./define";

// V2 · the whole site in one object; `tbtop-cms` CLI reads this file's default export.
export default defineConfig({
	blocks: {
		Hero: hero,
		Promo,
	},
	pageTypes: {
		page: { form: (s) => [s.text("tagline").label("Tagline").translatable()], schema: z.object({ tagline: z.string().nullable() }) },
		service: {
			label: "Service",
			schemaTypes: ["Service"],
			form: (s) => [s.number("price").label("Price, UAH"), s.text("duration").label("Duration").translatable()],
			schema: z.object({ price: z.number().nullable(), duration: z.string().nullable() }),
		},
	},
	seo: {
		schemaTypes: { Service: (ctx) => [serviceNode(baseNode(ctx, "Service"), identityId(ctx.site), ctx.page.fields?.price)] },
		extend: (nodes, ctx) => [...nodes.filter((node) => node["@id"] !== identityId(ctx.site)), organization(identityId(ctx.site))],
	},
	settings: { site: [{ label: "Company", fields: (s) => [s.text("phone").label("Phone")] }] },
});
