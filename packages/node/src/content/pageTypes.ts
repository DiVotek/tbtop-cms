import { S } from "@tbtop/admin";
import type { z } from "zod";
import type { ResolveContext } from "../blocks/context";
import { formFields, resolveForm } from "../blocks/fields";
import type { MarkdownContext } from "../seo/markdown/context";
import type { Page } from "./page";
import type { Site } from "../site";

/** What a source-backed type's `source()` and a type's `requestSeo()` read. */
export type SourceContext = {
	locale: string;
	/** The request's URL on a frontend render; null elsewhere (a sitemap, a task). */
	url: URL | null;
	site: Site;
};

/** A type's per-request SEO change: what it sets replaces the page's own. */
export type RequestSeo = { noindex?: boolean; canonical?: string };

/** A link on a source-backed page's Source card (the product card in a shop, the type's template). */
export type SourceLink = { label: string; url: string };

/** The zod object a page type's resolved custom fields match. */
export type FieldsSchema = z.ZodObject;

/** A page type (PageTypeInterface): tree rules, schema types and custom fields. */
export type PageTypeDef = {
	key: string;
	label?: string;
	/** Child types a page of this type may hold. */
	allowedChildren?: readonly string[];
	requiresParent?: boolean;
	maxDepth?: number;
	/** JSON-LD schema type keys the editor may pick; the first is the default. */
	schemaTypes?: readonly string[];
	/** A subtype names its base type; a base names itself. */
	base?: string;
	/**
	 * The resolved custom fields as a zod object: it types `page.fields` in
	 * PageProps, and resolved fields that fail it are dropped with a warning
	 * (thrown under `strictBlocks`). Its keys must be the form's field names.
	 * (PHP's `fields(S $s)` is the form; here that is `form`.)
	 */
	schema?: FieldsSchema;
	/** The admin form of the custom fields, stored in the page's `fields` column. */
	form?: (s: S) => unknown[];
	/** Flattened input to the resolved fields; default: FormResolver over `form`. */
	resolveFields?: (input: Record<string, unknown>, ctx: ResolveContext) => Record<string, unknown> | Promise<Record<string, unknown>>;
	/** Block content for a page that has none of its own. */
	template?: (page: Page) => unknown[] | null;
	/**
	 * False keeps this type's pages off the admin Pages list's "All" tab (a
	 * subtype gets a tab of its own): tags would crowd out the content.
	 * Default true; a subtype inherits its base's.
	 */
	listedInAll?: boolean;
	/**
	 * The pages field this type is an archive of (a tag of `tags`): a page of
	 * it that no live page picks through that field is noindex and left out
	 * of the sitemap. A subtype inherits its base's.
	 */
	archiveOf?: string;
	/**
	 * Markdown after the blocks in the page's `.md` version, or null for
	 * nothing. Default: a type that allows children lists its live, indexable
	 * ones (`## In this section`). A subtype inherits its base's.
	 */
	toMarkdown?: (page: Page, ctx: MarkdownContext) => string | null | Promise<string | null>;
	/**
	 * A source-backed type's pages mirror a record kept elsewhere (a shop's
	 * product): code creates them by `(type, source_id)` (`site.pages.ensureSource`),
	 * the admin's "New page" does not offer the type, and the editor shows a
	 * Source card instead of blocks, which come from the type's template. A
	 * subtype inherits its base's.
	 */
	sourceBacked?: boolean;
	/**
	 * The page's source for this request (`ctx.source` in a resolve), read
	 * once per page and locale; null answers the page like an unpublished one
	 * (not found, then redirects). Only on a source-backed type.
	 */
	source?: (page: Page, ctx: SourceContext) => unknown | Promise<unknown>;
	/** The Source card's links: where the source is edited, where the type's template is. */
	sourceLinks?: (page: Page) => SourceLink[] | Promise<SourceLink[]>;
	/**
	 * Per-request SEO after the page's own (a listing with an open filter:
	 * `noindex`, its canonical the unfiltered page). `source` is the page's
	 * source, null for a type without one.
	 */
	requestSeo?: (page: Page, ctx: SourceContext & { source: unknown }) => RequestSeo | null | Promise<RequestSeo | null>;
};

export type PageType = Required<Omit<PageTypeDef, "template" | "resolveFields" | "schema" | "archiveOf" | "toMarkdown" | "source" | "sourceLinks" | "requestSeo">> & {
	source: NonNullable<PageTypeDef["source"]> | null;
	sourceLinks: NonNullable<PageTypeDef["sourceLinks"]> | null;
	requestSeo: NonNullable<PageTypeDef["requestSeo"]> | null;
	archiveOf: string | null;
	/** Null: the default (the children list for a type that allows children). */
	toMarkdown: NonNullable<PageTypeDef["toMarkdown"]> | null;
	schema: FieldsSchema | null;
	template: (page: Page) => unknown[] | null;
	resolveFields: (input: Record<string, unknown>, ctx: ResolveContext) => Promise<Record<string, unknown>>;
};

/** Keeps the literal key, base and schema, so createCms types `page.fields` per page type. */
export function definePageType<const D extends PageTypeDef>(def: D): D {
	return def;
}

const WEB_PAGE_FAMILY = ["WebPage", "AboutPage", "ContactPage", "ItemPage"];

export const builtInPageTypes = [
	{ key: "page" },
	{ key: "category", allowedChildren: ["category", "article"], maxDepth: 3, schemaTypes: ["CollectionPage"] },
	{ key: "article", requiresParent: true, schemaTypes: ["Article", "NewsArticle", "BlogPosting"] },
] as const satisfies readonly PageTypeDef[];

/**
 * The form's field names must be the schema's keys, so a typed field is one
 * the editor stores and the resolver fills. Without a form, a custom
 * resolveFields fills the schema on its own.
 */
function assertFormMatchesSchema(def: PageTypeDef): void {
	if (def.schema === undefined || (def.form === undefined && def.resolveFields !== undefined)) {
		return;
	}
	const keys = Object.keys(def.schema.shape);
	const names = def.form === undefined ? [] : formFields(def.form(new S())).map((field) => field.name);
	const missingInForm = keys.filter((key) => !names.includes(key));
	const missingInSchema = names.filter((name) => !keys.includes(name));
	if (missingInForm.length === 0 && missingInSchema.length === 0) {
		return;
	}
	const problems = [
		...(missingInForm.length > 0 ? [`no form field for ${missingInForm.join(", ")}`] : []),
		...(missingInSchema.length > 0 ? [`no schema key for ${missingInSchema.join(", ")}`] : []),
	];
	throw new Error(`Page type [${def.key}]: its form() fields do not match its schema: ${problems.join("; ")}.`);
}

/** What a built-in type keeps when the host extends it: the PHP tree rules. */
const TREE_RULES = ["allowedChildren", "requiresParent", "maxDepth", "base"] as const;

/**
 * The built-ins with the host's extensions merged in, then the host's own
 * types. A host def with a built-in key extends that type (label, schema
 * types, fields, template) once; its tree rules stay the package's.
 */
function withExtensions(extra: readonly PageTypeDef[]): PageTypeDef[] {
	const builtIns: PageTypeDef[] = [...builtInPageTypes];
	const extended = new Set<string>();
	const own: PageTypeDef[] = [];
	for (const def of extra) {
		const index = builtIns.findIndex((builtIn) => builtIn.key === def.key);
		if (index === -1) {
			own.push(def);
			continue;
		}
		if (extended.has(def.key)) {
			throw new Error(`Page type [${def.key}] is already registered.`);
		}
		const rules = TREE_RULES.filter((rule) => def[rule] !== undefined);
		if (rules.length > 0) {
			throw new Error(`Page type [${def.key}] is built in: extend its fields, label, schemaTypes or template, not ${rules.join(", ")}.`);
		}
		// Only the keys the host set: an explicit undefined must not erase a built-in value.
		const set = Object.fromEntries(Object.entries(def).filter(([, value]) => value !== undefined));
		builtIns[index] = { ...builtIns[index], ...set } as PageTypeDef;
		extended.add(def.key);
	}
	return [...builtIns, ...own];
}

/** A subtype without its own schemaTypes inherits its base's, as a PHP subtype class inherits the method. */
function normalize(def: PageTypeDef, baseDef: PageTypeDef | undefined): PageType {
	const form = def.form ?? (() => []);
	const schema = def.schema ?? null;
	const resolve = async (input: Record<string, unknown>, ctx: ResolveContext) =>
		def.resolveFields === undefined ? resolveForm(form(new S()), input, ctx) : def.resolveFields(input, ctx);
	return {
		key: def.key,
		label: def.label ?? def.key.charAt(0).toUpperCase() + def.key.slice(1),
		allowedChildren: def.allowedChildren ?? [],
		requiresParent: def.requiresParent ?? false,
		maxDepth: def.maxDepth ?? 1,
		schemaTypes: def.schemaTypes ?? baseDef?.schemaTypes ?? WEB_PAGE_FAMILY,
		base: def.base ?? def.key,
		schema,
		form,
		template: def.template ?? (() => null),
		listedInAll: def.listedInAll ?? baseDef?.listedInAll ?? true,
		archiveOf: def.archiveOf ?? baseDef?.archiveOf ?? null,
		toMarkdown: def.toMarkdown ?? baseDef?.toMarkdown ?? null,
		sourceBacked: def.sourceBacked ?? baseDef?.sourceBacked ?? false,
		source: def.source ?? baseDef?.source ?? null,
		sourceLinks: def.sourceLinks ?? baseDef?.sourceLinks ?? null,
		requestSeo: def.requestSeo ?? baseDef?.requestSeo ?? null,
		resolveFields: async (input, ctx) => {
			const resolved = await resolve(input, ctx);
			if (schema === null) {
				return resolved;
			}
			const result = schema.safeParse(resolved);
			if (!result.success) {
				throw new Error(`Page type [${def.key}] fields do not match its schema: ${result.error.message}`);
			}
			return result.data;
		},
	};
}

/** PageTypeRegistry: the built-ins (extended by the host) plus the host's, keys unique. */
export class PageTypes {
	private readonly types = new Map<string, PageType>();

	constructor(extra: readonly PageTypeDef[] = []) {
		const defs = withExtensions(extra);
		for (const def of defs) {
			if (def.key === "") {
				throw new Error("A page type needs a non-empty key.");
			}
			if (this.types.has(def.key)) {
				throw new Error(`Page type [${def.key}] is already registered.`);
			}
			assertFormMatchesSchema(def);
			if (def.source !== undefined && def.sourceBacked !== true && defs.find((other) => other.key === def.base)?.sourceBacked !== true) {
				throw new Error(`Page type [${def.key}]: source() needs sourceBacked: true.`);
			}
			this.types.set(def.key, normalize(def, defs.find((other) => other.key === def.base)));
		}
	}

	has(key: string): boolean {
		return this.types.has(key);
	}

	/**
	 * Block content for a page that has none of its own: its subtype's
	 * template when that returns one, else its base type's.
	 */
	templateFor(page: Page): unknown[] | null {
		const own = page.subtype !== null && this.has(page.subtype) ? this.get(page.subtype).template(page) : null;
		return own ?? (this.has(page.type) ? this.get(page.type).template(page) : null);
	}

	get(key: string): PageType {
		const type = this.types.get(key);
		if (type === undefined) {
			throw new Error(`Page type [${key}] is not registered.`);
		}
		return type;
	}

	all(): PageType[] {
		return [...this.types.values()];
	}

	/** Base types only: what the "new page" picker lists. */
	bases(): PageType[] {
		return this.all().filter((type) => type.base === type.key);
	}

	/** Base types an editor may create or switch a page to: not the source-backed ones, which code creates. */
	creatable(): PageType[] {
		return this.bases().filter((type) => !type.sourceBacked);
	}

	/** Whether the page's own type mirrors a source (its pages are made by code). */
	isSourceBacked(page: Pick<Page, "type" | "subtype">): boolean {
		return this.ownType(page)?.sourceBacked ?? false;
	}

	subtypesOf(base: string): PageType[] {
		return this.all().filter((type) => type.base === base && type.key !== base);
	}

	/** The page's type, then its subtype when registered. */
	forPage(page: Page): PageType[] {
		return [this.types.get(page.type), page.subtype ? this.types.get(page.subtype) : undefined].filter(
			(type): type is PageType => type !== undefined,
		);
	}

	schemaTypesFor(page: Page): readonly string[] {
		return this.forPage(page).at(-1)?.schemaTypes ?? [];
	}

	/** The type that speaks for a page: its subtype when registered, else its base type. */
	ownType(page: Pick<Page, "type" | "subtype">): PageType | null {
		return (page.subtype ? this.types.get(page.subtype) : undefined) ?? this.types.get(page.type) ?? null;
	}

	/** Type keys (base or subtype) whose pages the "All" tab leaves out. */
	unlistedInAll(): string[] {
		return this.all()
			.filter((type) => !type.listedInAll)
			.map((type) => type.key);
	}
}

type Key<D> = D extends { key: infer K extends string } ? K : never;
type BaseKey<D> = D extends { base: infer B extends string } ? B : Key<D>;
/** A def's resolved fields: its schema's output; any keys for a form without a schema; none without either. */
type FieldsOf<D> = D extends { schema: infer F extends FieldsSchema } ? z.output<F> : D extends { form: unknown } ? Record<string, unknown> : Record<never, never>;
type Intersect<U> = (U extends unknown ? (x: U) => void : never) extends (x: infer I) => void ? I : never;
type OwnFields<D, B> = D extends unknown ? (Key<D> extends B ? FieldsOf<D> : never) : never;
type SubtypeFields<D, B> = D extends unknown ? (BaseKey<D> extends B ? (Key<D> extends B ? never : FieldsOf<D>) : never) : never;
type Bases<D> = D extends unknown ? (BaseKey<D> extends Key<D> ? Key<D> : never) : never;
/** A page of base type B: its own fields, plus its subtypes' as optional (a page carries at most one subtype). */
type TypedPage<D, B extends string> = {
	type: B;
	// Intersected: a host def that extends a built-in key adds to that type's (empty) fields.
	fields: [SubtypeFields<D, B>] extends [never] ? Intersect<OwnFields<D, B>> : Intersect<OwnFields<D, B>> & Partial<Intersect<SubtypeFields<D, B>>>;
};

/**
 * `{ type, fields }` of a page, one member per base type of the built-ins
 * and `T`, so a check on `page.type` narrows `page.fields`. A list without
 * literal keys gives `{ type: string; fields: Record<string, unknown> }`.
 */
export type PageTypeUnion<T extends readonly PageTypeDef[]> = string extends Key<T[number]>
	? { type: string; fields: Record<string, unknown> }
	: Bases<(typeof builtInPageTypes)[number] | T[number]> extends infer B
		? B extends string
			? TypedPage<(typeof builtInPageTypes)[number] | T[number], B>
			: never
		: never;
