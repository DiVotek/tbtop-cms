import { z } from "zod";
import { safeHref } from "../content/safeHref";

/**
 * Where an editor can place a block (Block::placements): `pages` is every
 * page type, including one registered later; `layout` the header/footer
 * slots; any other string names a page type or subtype key (`article`,
 * `news`), so the block is offered only on those pages.
 */
export type Placement = "pages" | "layout" | (string & {});

/**
 * BlockRegistry::forPlacements: can a block with `placements` go where
 * `where` says? A page passes its type and subtype, matched by name or by the
 * `pages` wildcard; the layout passes `layout`, matched by name only.
 */
export function placeableIn(placements: readonly Placement[], where: readonly string[]): boolean {
	return where.some((place) => placements.includes(place) || (place !== "layout" && placements.includes("pages")));
}

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
			// The resolver already runs safeHref; the schema also refuses javascript:/data: from any other source of props.
			cta: z.object({ label: z.string(), href: z.string().refine((href) => safeHref(href) !== null, "Unsafe link scheme.") }).nullable(),
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
