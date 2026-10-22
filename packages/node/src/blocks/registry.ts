import { S } from "@tbtop/admin";
import type { z } from "zod";
import type { SchemaContext } from "../seo/schemaContext";
import type { BlockMeta, Catalog, Placement } from "./catalog";
import { cmsBlocks } from "./catalog";
import type { ResolveContext } from "./context";
import { resolveForm } from "./fields";
import { translatablePaths } from "./locale";

/** The server half of a block kind: its editor form and how stored input becomes props. */
export type BlockImpl<P = unknown> = {
	/** The admin form (fields of the `S` DSL); translatable() fields store locale maps. */
	form: (s: S) => unknown[];
	/** Flattened input to props; default: FormResolver over `form`. */
	resolve?: (input: Record<string, unknown>, ctx: ResolveContext) => P | Promise<P>;
	/** A JSON-LD node the block adds to the page, or null. */
	toSchema?: (props: P, ctx: SchemaContext) => Record<string, unknown> | null;
	/** The block posts a form (`action` is bound to the submit URL). */
	handlesSubmission?: boolean;
};

export type Implementations<C extends Catalog> = { [K in keyof C]: BlockImpl<z.infer<C[K]["props"]>> };

/** One registered kind: catalog meta and server implementation together. */
export type BlockKind = {
	kind: string;
	label: string;
	description: string | null;
	category: string | null;
	placements: Placement[];
	props: z.ZodType;
	form: (s: S) => unknown[];
	resolve: (input: Record<string, unknown>, ctx: ResolveContext) => Promise<unknown>;
	translatableFields: () => string[];
	toSchema: (props: unknown, ctx: SchemaContext) => Record<string, unknown> | null;
	handlesSubmission: boolean;
};

export type BlockRegistry = {
	has(kind: string): boolean;
	get(kind: string): BlockKind;
	all(): BlockKind[];
	/** Kinds an editor can add in a placement. */
	forPlacement(placement: Placement): BlockKind[];
};

const headline = (kind: string) => kind.replace(/([a-z0-9])([A-Z])/g, "$1 $2").replace(/[_-]+/g, " ");

/**
 * Pairs each catalog kind with its implementation, so a kind without a form
 * or a form without a kind fails to typecheck. The result goes to createCms.
 */
export function implementBlocks<const C extends Catalog>(catalog: C, impls: Implementations<C>): BlockRegistry {
	const kinds = new Map<string, BlockKind>();
	for (const [kind, meta] of Object.entries(catalog) as [string, BlockMeta][]) {
		const impl = (impls as Record<string, BlockImpl | undefined>)[kind];
		if (impl === undefined) {
			throw new Error(`Block [${kind}] has no implementation.`);
		}
		kinds.set(kind, {
			kind,
			label: meta.label ?? headline(kind),
			description: meta.description ?? null,
			category: meta.category ?? null,
			placements: meta.placements ?? ["pages"],
			props: meta.props,
			form: impl.form,
			resolve: async (input, ctx) =>
				impl.resolve === undefined ? resolveForm(impl.form(new S()), input, ctx) : impl.resolve(input, ctx),
			translatableFields: () => translatablePaths(impl.form(new S())),
			toSchema: (props, ctx) => impl.toSchema?.(props, ctx) ?? null,
			handlesSubmission: impl.handlesSubmission ?? false,
		});
	}
	return {
		has: (kind) => kinds.has(kind),
		get: (kind) => {
			const block = kinds.get(kind);
			if (block === undefined) {
				throw new Error(`Block [${kind}] is not registered.`);
			}
			return block;
		},
		all: () => [...kinds.values()],
		forPlacement: (placement) => [...kinds.values()].filter((block) => block.placements.includes(placement)),
	};
}

const CONTACT_FIELDS = [
	{ name: "name", type: "text", required: true },
	{ name: "email", type: "email", required: true },
	{ name: "message", type: "textarea", required: true },
] as const;

const cleanString = (value: unknown): string | null => {
	if (typeof value !== "string") {
		return null;
	}
	const trimmed = value.trim();
	return trimmed === "" ? null : trimmed;
};

/** The built-in kinds' server half (HeroBlock.php, ContactForm.php). */
export const cmsBlockImpls: Implementations<typeof cmsBlocks> = {
	Hero: {
		form: (s) => [
			s.text("title").label("Title").translatable().required(),
			s.textarea("subtitle").label("Subtitle").translatable(),
			s.text("image").label("Image URL"),
			s.text("ctaLabel").label("CTA label").translatable(),
			s.text("ctaHref").label("CTA link"),
		],
		resolve: (input) => {
			const label = cleanString(input.ctaLabel);
			const href = cleanString(input.ctaHref);
			return {
				title: typeof input.title === "string" ? input.title : input.title == null ? "" : String(input.title),
				subtitle: cleanString(input.subtitle),
				image: cleanString(input.image),
				cta: label === null || href === null ? null : { label, href },
			};
		},
	},
	ContactForm: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s.text("submitLabel").label("Submit button label").translatable(),
			s.textarea("successMessage").label("Success message").translatable(),
		],
		resolve: (input, ctx) => {
			const copy = (value: unknown, key: string) =>
				["string", "number"].includes(typeof value) && String(value).trim() !== "" ? String(value) : ctx.t(`forms.contact.${key}`);
			return {
				heading: copy(input.heading, "heading"),
				submitLabel: copy(input.submitLabel, "submit"),
				successMessage: copy(input.successMessage, "success"),
				fields: CONTACT_FIELDS.map((field) => ({ ...field, label: ctx.t(`forms.contact.fields.${field.name}`) })),
				// FormActionBinder replaces it with the submit URL once the page is known.
				action: "",
			};
		},
		handlesSubmission: true,
	},
};
