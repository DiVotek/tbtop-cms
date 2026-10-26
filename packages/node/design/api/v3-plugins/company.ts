import { z } from "zod";
import { baseNode, definePageType, identityId } from "../../../src";
import { organization, serviceNode } from "../shared/reference";
import { Promo } from "../v2-config/blocks";
import { definePlugin } from "./define";

// V3 · the site's own code is a plugin like any other; a "services" plugin could ship on npm the same way.


export const company = definePlugin({
	name: "divotek",
	blocks: { Promo },
	pageTypes: [
		definePageType({ key: "page", form: (s) => [s.text("tagline").label("Tagline").translatable()], schema: z.object({ tagline: z.string().nullable() }) }),
		definePageType({
			key: "service",
			label: "Service",
			schemaTypes: ["Service"],
			form: (s) => [s.number("price").label("Price, UAH"), s.text("duration").label("Duration").translatable()],
			schema: z.object({ price: z.number().nullable(), duration: z.string().nullable() }),
		}),
	],
	schemaTypes: [{ key: "Service", build: (ctx) => [serviceNode(baseNode(ctx, "Service"), identityId(ctx.site), ctx.page.fields?.price)] }],
	seo: { extend: (nodes, ctx) => [...nodes.filter((node) => node["@id"] !== identityId(ctx.site)), organization(identityId(ctx.site))] },
	settings: { site: [{ label: "Company", fields: (s) => [s.text("phone").label("Phone")] }] },
});
