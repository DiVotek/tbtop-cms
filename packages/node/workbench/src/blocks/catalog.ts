import { cmsBlocks, defineBlocks } from "@tbtop/cms/catalog";
import { z } from "zod";

// The block catalog shared by the server (implementBlocks) and the browser
// (cmsCatalog): the built-in kinds plus the workbench's own Promo, whose CTA
// is a CMS link field (page, URL), resolved to { href, type, label } or null.
export const catalog = defineBlocks({
	...cmsBlocks,
	Promo: {
		label: "Promo",
		description: "A short call to action: a line of text and a button to a page or a URL.",
		category: "content",
		props: z.object({
			title: z.string().nullable(),
			cta: z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable(),
		}),
	},
});
