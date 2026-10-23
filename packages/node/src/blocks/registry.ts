import { S } from "@tbtop/admin";
import type { z } from "zod";
import type { Page } from "../content/page";
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
	/** The block posts a form: `action` (and `honeypotField`) are bound into its props, `/_cms/forms/...` takes the post. */
	submit?: SubmitHandler;
	/**
	 * HasForm: the form's stable name, which makes it postable from any live
	 * page, not only where an editor placed the block. A page type template or
	 * the host renders it from `site.formProps(name, page)`; a post by name
	 * runs `submit` with empty block data. Needs `submit`; unique per registry.
	 */
	formName?: string;
};

/** What a submit needs besides the payload (SubmitContext.php). */
export type SubmitContext = { locale: string; fallbackLocale: string; page: Page };

/** HandlesFormSubmission: the payload's rules and what a valid submission does. */
export type SubmitHandler = {
	/** Laravel validation rules by field (`required|email|max:200`), checked by @tbtop/admin's validator. */
	rules: (blockData: Record<string, unknown>, ctx: SubmitContext) => Record<string, string | string[]>;
	/**
	 * The block's own delivery (forward to a CRM, a queue). Runs once per
	 * submission; a throw fails it and the visitor sees an error. The team's
	 * mail/Telegram/bell notifications are the CMS's job, not this.
	 */
	handle?: (payload: Record<string, unknown>, blockData: Record<string, unknown>, ctx: SubmitContext) => void | Promise<void>;
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
	submit: SubmitHandler | null;
	/** The HasForm name, or null when the form is reachable only where it is placed. */
	formName: string | null;
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
	const formNames = new Map<string, string>();
	for (const [kind, meta] of Object.entries(catalog) as [string, BlockMeta][]) {
		const impl = (impls as Record<string, BlockImpl | undefined>)[kind];
		if (impl === undefined) {
			throw new Error(`Block [${kind}] has no implementation.`);
		}
		if (impl.formName !== undefined) {
			namedForm(formNames, kind, impl);
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
			submit: impl.submit ?? null,
			formName: impl.formName ?? null,
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

/** Forms::collect: a name needs a submit handler and names one kind only. */
function namedForm(names: Map<string, string>, kind: string, impl: BlockImpl): void {
	const name = impl.formName ?? kind;
	if (name.trim() === "" || name.includes("/")) {
		throw new Error(`Block [${kind}] declares a form name that cannot be a URL segment: [${name}].`);
	}
	if (impl.submit === undefined) {
		throw new Error(`Block [${kind}] declares form [${name}] but has no submit handler.`);
	}
	const taken = names.get(name);
	if (taken !== undefined) {
		throw new Error(`Form [${name}] is declared by both [${taken}] and [${kind}].`);
	}
	names.set(name, kind);
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
		submit: {
			rules: () => ({
				name: "required|string|max:200",
				email: "required|email|max:200",
				message: "required|string|max:5000",
			}),
		},
	},
};
