import { z } from "zod";
import { hero } from "../kit/hero";
import { organization, promoProps, serviceNode } from "../shared/reference";
import { tbtop } from "./define";

// V4 · the site as one chain. No server import here: the browser can import `site.catalog`.

export const site = tbtop()
	.block("Hero", hero)
	.block("Promo", {
		label: "Promo",
		category: "content",
		props: promoProps,
		form: (s, f) => [s.text("title").label("Title").translatable(), f.link("cta").label("Button")],
	})
	.pageType("page", { form: (s) => [s.text("tagline").label("Tagline").translatable()], schema: z.object({ tagline: z.string().nullable() }) })
	.pageType("service", {
		label: "Service",
		schemaTypes: ["Service"],
		form: (s) => [s.number("price").label("Price, UAH"), s.text("duration").label("Duration").translatable()],
		schema: z.object({ price: z.number().nullable(), duration: z.string().nullable() }),
	})
	.schemaType("Service", (ctx, h) => [serviceNode(h.baseNode("Service"), h.identityId, ctx.page.fields?.price)])
	.seo((nodes, _ctx, h) => [...nodes.filter((node) => node["@id"] !== h.identityId), organization(h.identityId)])
	.settingsTab("Company", (s) => [s.text("phone").label("Phone")]);
