import { cmsBlocks, defineBlocks } from "@tbtop/cms/catalog";
import { z } from "zod";

const link = z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable();
const localeItems = z.record(z.string(), z.array(z.object({ title: z.string(), body: z.string() })));
const localeCases = z.record(z.string(), z.array(z.object({ title: z.string(), tag: z.string(), href: z.string() })));
const localeStats = z.record(z.string(), z.array(z.object({ value: z.string(), label: z.string() })));

export const catalog = defineBlocks({
	...cmsBlocks,
	SiteNav: {
		label: "Site navigation",
		description: "Header chrome: logo and primary links from site settings.",
		category: "layout",
		props: z.object({}),
		placements: ["layout"],
	},
	SiteFooter: {
		label: "Site footer",
		description: "Footer chrome with contact lines and social links.",
		category: "layout",
		props: z.object({}),
		placements: ["layout"],
	},
	StudioHero: {
		label: "Studio hero",
		description: "Large hero with eyebrow, title, subtitle, and two CTAs.",
		category: "content",
		props: z.object({
			eyebrow: z.string().nullable(),
			title: z.string().nullable(),
			subtitle: z.string().nullable(),
			cta: link,
			secondary: link,
		}),
	},
	ServiceGrid: {
		label: "Service grid",
		description: "Three-column services section.",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			items: z.array(z.object({ title: z.string(), body: z.string() })),
		}),
	},
	StatBand: {
		label: "Stat band",
		description: "Horizontal band of headline stats.",
		category: "content",
		props: z.object({
			stats: z.array(z.object({ value: z.string(), label: z.string() })),
		}),
	},
	TeamStrip: {
		label: "Team strip",
		description: "Simple team member row.",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			members: z.array(z.object({ name: z.string(), role: z.string() })),
		}),
	},
	CaseStudyList: {
		label: "Case studies",
		description: "Linked case study cards.",
		category: "content",
		props: z.object({
			cases: z.array(z.object({ title: z.string(), tag: z.string(), href: z.string() })),
		}),
	},
	PullQuote: {
		label: "Pull quote",
		description: "Article pull quote.",
		category: "content",
		props: z.object({
			quote: z.string().nullable(),
			attribution: z.string().nullable(),
		}),
		placements: ["pages", "article", "journal"],
	},
	Newsletter: {
		label: "Newsletter signup",
		description: "Email-only signup; notifications go to the studio inbox.",
		category: "content",
		props: z.object({
			heading: z.string().nullable(),
			description: z.string().nullable(),
			submitLabel: z.string(),
			successMessage: z.string(),
			action: z.string(),
			honeypotField: z.string().nullable(),
		}),
	},
});

/** Schemas used only on the server when resolving translatable JSON blobs. */
export const serverSchemas = { localeItems, localeCases, localeStats };
