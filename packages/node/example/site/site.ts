import { block, defineSettings, defineSite, f, formBlock, hero, pageType } from "@tbtop/cms/site";
import { z } from "zod";

// The whole site, as records of fields. Client-safe: the browser imports
// `site.catalog` from this same file for its block registry.
//
// Every extension point of `defineSite` is used once:
// - blocks: the opt-in Hero, a link block, a repeater block, a block whose
//   props come from its own resolve, a form block
// - pages: the built-in `page` type extended, a new `service` type with its own JSON-LD
// - jsonLd: the `Service` schema type the service pages default to
// - seo: every page's JSON-LD, last (the company's Organization at the identity @id)
// - settings: two Site settings tabs, declared apart so a block reads them typed

export const settings = defineSettings({
	Company: {
		phone: f.text().label("Phone"),
		address: f.textarea().label("Address").translatable(),
		// Any admin field the f.* set does not cover, with the value it resolves to.
		brandColor: f.custom((s, name) => s.colorpicker(name), z.string().nullable()).label("Brand color"),
	},
	Social: { links: f.repeater({ network: f.select({ github: "GitHub", telegram: "Telegram" }), url: f.text() }).label("Links") },
});

export const site = defineSite({
	blocks: {
		Hero: hero,
		Promo: block(
			{
				title: f.text().label("Title").translatable(),
				cta: f.link(["page", "external"]).label("Button"),
			},
			{ label: "Promo", category: "content", description: "A line of text and a button." },
		),
		Faq: block(
			{
				heading: f.text().label("Heading").translatable(),
				items: f.repeater({ question: f.text().label("Question").translatable(), answer: f.textarea().label("Answer").translatable() }).label("Questions"),
			},
			{
				label: "FAQ",
				category: "content",
				// A block may add its own JSON-LD node to the page.
				toSchema: (props) =>
					props.items.length === 0
						? null
						: { "@type": "FAQPage", mainEntity: props.items.map((item) => ({ "@type": "Question", name: item.question, acceptedAnswer: { "@type": "Answer", text: item.answer } })) },
			},
		),
		Contacts: block(
			{ heading: f.text().label("Heading").translatable() },
			{
				label: "Contacts",
				category: "content",
				// The editor fills the heading; the props are what resolve returns, typed from it.
				resolve: async (fields, ctx) => {
					const { phone, address } = await settings.of(ctx);
					return { heading: fields.heading ?? "", phone: phone ?? "", address };
				},
			},
		),
		Callback: formBlock(
			{ heading: f.text().label("Heading").translatable(), button: f.text().label("Button").translatable() },
			{
				label: "Call me back",
				category: "forms",
				// The posted payload, validated (any Standard Schema). Stored as a submission; the team's notifications are the CMS's job.
				submit: { schema: z.object({ name: z.string().min(1).max(200), phone: z.string().max(40).regex(/^\+?[0-9 ()-]{7,}$/) }) },
			},
		),
	},
	pages: {
		page: { tagline: f.text().label("Tagline").translatable() },
		service: pageType(
			{
				price: f.number().label("Price, UAH"),
				duration: f.text().label("Duration").translatable(),
				level: f.select({ basic: "Basic", pro: "Pro" }).label("Level"),
				channels: f.select({ web: "Web", mobile: "Mobile", seo: "SEO" }, { multiple: true }).label("Channels"),
				starts: f.date().label("Next start"),
				keywords: f.tags().label("Keywords"),
				related: f.pages("service").label("Related services"),
			},
			{ label: "Service", jsonLd: "Service" },
		),
	},
	jsonLd: {
		Service: (ctx, h) => [
			{
				...h.baseNode("Service"),
				provider: { "@id": h.identityId },
				...(typeof ctx.page.fields?.price === "number" ? { offers: { "@type": "Offer", price: ctx.page.fields.price, priceCurrency: "UAH" } } : {}),
			},
		],
	},
	seo: async (nodes, ctx, h) => {
		const { name, phone } = await settings.of(ctx);
		return [
			...nodes.filter((node) => node["@id"] !== h.identityId),
			{ "@context": "https://schema.org", "@type": "Organization", "@id": h.identityId, name, telephone: phone, sameAs: ["https://github.com/DiVotek"] },
		];
	},
	settings,
});
