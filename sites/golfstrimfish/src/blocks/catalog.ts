import { cmsBlocks, defineBlocks } from "@tbtop/cms/catalog";
import { z } from "zod";

const link = z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable();
const pageRef = z.object({ id: z.string(), name: z.string(), url: z.string() });

export const catalog = defineBlocks({
	...cmsBlocks,
	SiteNav: {
		label: "Site navigation",
		description: "Primary header with logo and links.",
		category: "layout",
		placements: ["layout"],
		props: z.object({
			siteName: z.string(),
			tagline: z.string().nullable(),
			links: z.array(z.object({ label: z.string(), href: z.string() })),
		}),
	},
	SiteFooter: {
		label: "Site footer",
		category: "layout",
		placements: ["layout"],
		props: z.object({
			siteName: z.string(),
			phone: z.string().nullable(),
			email: z.string().nullable(),
			links: z.array(z.object({ label: z.string(), href: z.string() })),
			note: z.string().nullable(),
		}),
	},
	CategoryCards: {
		label: "Product categories",
		description: "Grid of category cards with image and link.",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			items: z.array(z.object({ title: z.string(), image: z.string().nullable(), link: link })),
		}),
	},
	StatsStrip: {
		label: "Statistics",
		category: "content",
		props: z.object({
			items: z.array(z.object({ value: z.string(), label: z.string() })),
		}),
	},
	AboutSection: {
		label: "About section",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			body: z.string(),
		}),
	},
	FeatureGrid: {
		label: "Feature grid",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			items: z.array(z.object({ title: z.string(), text: z.string().nullable() })),
		}),
	},
	ProcessSteps: {
		label: "Process steps",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			steps: z.array(z.object({ title: z.string(), text: z.string() })),
		}),
	},
	ProductGrid: {
		label: "Product grid",
		description: "Lists catalog products selected via the pages field.",
		category: "content",
		placements: ["pages", "category"],
		props: z.object({
			heading: z.string().nullable(),
			products: z.array(pageRef),
		}),
	},
	ProductDetail: {
		label: "Product details",
		description: "Price and unit for a product page (from page type fields).",
		category: "content",
		placements: ["article/product"],
		props: z.object({
			price: z.number().nullable(),
			unit: z.string().nullable(),
			note: z.string().nullable(),
		}),
	},
	StoreList: {
		label: "Retail locations",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			locations: z.array(z.object({ city: z.string().nullable(), address: z.string() })),
		}),
	},
	CoopBenefits: {
		label: "Cooperation benefits",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			items: z.array(z.string()),
			body: z.string().nullable(),
		}),
	},
});
