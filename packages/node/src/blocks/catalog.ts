import { z } from "zod";

/** Where an editor can place a block: page bodies, the header/footer layout, or both. */
export type Placement = "pages" | "layout";

/** One block kind's shared, client-safe description: its resolved props and its picker copy. */
export type BlockMeta<P extends z.ZodType = z.ZodType> = {
	props: P;
	label?: string;
	description?: string;
	category?: string;
	placements?: Placement[];
};

export type Catalog = Record<string, BlockMeta>;

/**
 * The block catalog by kind, shared by server and client: the server resolves
 * props against it, the client (`defineRegistry` of json-render) renders them.
 * It holds only zod schemas and copy, so a browser bundle can import it.
 */
export function defineBlocks<const C extends Catalog>(catalog: C): C {
	return catalog;
}

export type PropsOf<C extends Catalog, K extends keyof C> = z.infer<C[K]["props"]>;

/** The built-in kinds, the same shapes the PHP package resolves. */
export const cmsBlocks = defineBlocks({
	Hero: {
		label: "Hero",
		description: "Full-width intro section: a headline, optional subtitle, image, and a call-to-action button.",
		category: "content",
		props: z.object({
			title: z.string(),
			subtitle: z.string().nullable(),
			image: z.string().nullable(),
			cta: z.object({ label: z.string(), href: z.string() }).nullable(),
		}),
	},
	ContactForm: {
		label: "Contact form",
		description:
			"Anonymous contact form. You edit the copy; the name, email, and message fields are built in. Submissions mail the global notification recipients.",
		category: "forms",
		props: z.object({
			heading: z.string(),
			submitLabel: z.string(),
			successMessage: z.string(),
			fields: z.array(z.object({ name: z.string(), label: z.string(), type: z.string(), required: z.boolean() })),
			action: z.string(),
			honeypotField: z.string().optional(),
		}),
	},
});
