import { S } from "@tbtop/admin";
import type { z } from "zod";
import type { ResolveContext } from "../blocks/context";
import { formFields, resolveForm } from "../blocks/fields";
import type { Page } from "./page";

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
};

export type PageType = Required<Omit<PageTypeDef, "template" | "resolveFields" | "schema">> & {
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
