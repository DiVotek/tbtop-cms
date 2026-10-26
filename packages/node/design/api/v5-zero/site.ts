import { organization, serviceNode } from "../shared/reference";
import { block, defineSite, f, hero, pageType } from "./define";

// V5 · the whole site. Client-safe: the browser imports `site.catalog` from here.
export const site = defineSite({
	blocks: {
		Hero: hero,
		Promo: block({ title: f.text().label("Title").translatable(), cta: f.link().label("Button") }, { label: "Promo", category: "content" }),
	},
	pages: {
		page: { tagline: f.text().label("Tagline").translatable() },
		service: pageType({ price: f.number().label("Price, UAH"), duration: f.text().label("Duration").translatable() }, { label: "Service", jsonLd: "Service" }),
	},
	jsonLd: { Service: (ctx, h) => [serviceNode(h.baseNode("Service"), h.identityId, ctx.page.fields?.price)] },
	seo: (nodes, _ctx, h) => [...nodes.filter((node) => node["@id"] !== h.identityId), organization(h.identityId)],
	settings: { Company: { phone: f.text().label("Phone") } },
});
