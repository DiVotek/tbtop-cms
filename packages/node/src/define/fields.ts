import type { S } from "@tbtop/admin";
import { z } from "zod";
import type { PickMode } from "../blocks/collectionField";
import type { LinkType } from "../content/link";
import { type CollectionType, collectionKeyOf, type EntryOf } from "./collections";

/**
 * The CMS's own admin fields, handed to a form next to `s` when the server
 * builds it, so a site file imports nothing that runs on the server.
 */
export type CmsFields = {
	link(name: string): { types(types: LinkType[]): unknown };
	pages(name: string): { of(type: string, subtype?: string): unknown };
	collection(name: string): { of(key: string): CollectionFieldLike };
};

type CollectionFieldLike = { single(): CollectionFieldLike; max(count: number): CollectionFieldLike; modes(modes: PickMode[]): CollectionFieldLike };

/** `f.collection()`'s options: one entry or null, a cap, the modes the editor may choose (the first is the default). */
type PickOptions = { max?: number; modes?: PickMode[] };

type FieldLike = { label(label: string): FieldLike; translatable(): FieldLike; required(): FieldLike; helperText(text: string): FieldLike };

/**
 * A field declared once: its admin field (built when the server asks for the
 * form) and, as zod, the value FormResolver makes of it. A block's props and
 * a page type's `page.fields` come out of the fields, so there is no second
 * schema to keep in step with the form.
 */
export class F<Out> {
	constructor(
		readonly zod: z.ZodType<Out>,
		private readonly make: (s: S, cms: CmsFields, name: string) => FieldLike,
		private readonly steps: readonly ((field: FieldLike) => FieldLike)[] = [],
	) {}

	private step(fn: (field: FieldLike) => FieldLike): F<Out> {
		return new F(this.zod, this.make, [...this.steps, fn]);
	}

	label(label: string): F<Out> {
		return this.step((field) => field.label(label));
	}

	helperText(text: string): F<Out> {
		return this.step((field) => field.helperText(text));
	}

	/** Stored per locale; the resolver hands the page's locale (the default as fallback), so the output type stays the same. */
	translatable(): F<Out> {
		return this.step((field) => field.translatable());
	}

	/** Required in the editor; the output keeps its null, since an older row may still be blank. */
	required(): F<Out> {
		return this.step((field) => field.required());
	}

	/** The admin field under `name`. */
	build(s: S, cms: CmsFields, name: string): unknown {
		return this.steps.reduce((field, step) => step(field), this.make(s, cms, name));
	}
}

const field = <Out>(zod: z.ZodType<Out>, make: (s: S, cms: CmsFields, name: string) => unknown) => new F<Out>(zod, make as (s: S, cms: CmsFields, name: string) => FieldLike);

// biome-ignore lint/suspicious/noExplicitAny: a record of fields of any output.
export type Fields = Record<string, F<any>>;
/** The resolved values of a field record. */
export type Output<R extends Fields> = { [K in keyof R]: R[K] extends F<infer O> ? O : never };

const link = z.object({ href: z.string(), type: z.string(), label: z.string() });
const pageRef = z.object({ id: z.string(), name: z.string(), url: z.string() });
const text = () => z.string().nullable();

/** A built-in (or any) collection named by key: its entries' fields are untyped. */
type AnyEntry = { id: number } & Record<string, unknown>;

const entry = z.looseObject({ id: z.number() });

function pick<R extends Fields>(target: CollectionType<R>, opts: PickOptions & { single: true }): F<EntryOf<R> | null>;
function pick<R extends Fields>(target: CollectionType<R>, opts?: PickOptions & { single?: false }): F<EntryOf<R>[]>;
function pick(target: string, opts: PickOptions & { single: true }): F<AnyEntry | null>;
function pick(target: string, opts?: PickOptions & { single?: false }): F<AnyEntry[]>;
// biome-ignore lint/suspicious/noExplicitAny: the implementation takes any collection.
function pick(target: CollectionType<any> | string, opts: PickOptions & { single?: boolean } = {}): F<unknown> {
	const one: z.ZodType = typeof target === "string" ? entry : entry.extend(zodOf(target.fields).shape);
	return field<unknown>(opts.single === true ? one.nullable() : z.array(one), (_s, cms, name) => {
		let built = cms.collection(name).of(collectionKeyOf(target));
		if (opts.single === true) {
			built = built.single();
		}
		if (opts.max !== undefined) {
			built = built.max(opts.max);
		}
		return opts.modes === undefined ? built : built.modes(opts.modes);
	});
}

const choices = (options: Record<string, string>) => Object.entries(options).map(([value, label]) => ({ value, label }));

function select<const V extends string>(options: Record<V, string>, opts?: { multiple?: false }): F<V | null>;
function select<const V extends string>(options: Record<V, string>, opts: { multiple: true }): F<V[]>;
function select<const V extends string>(options: Record<V, string>, opts: { multiple?: boolean } = {}): F<V | null> | F<V[]> {
	const values = z.enum(Object.keys(options) as [V, ...V[]]);
	return opts.multiple === true
		? field(z.array(values), (s, _cms, name) => s.select(name).options(choices(options)).multiple())
		: field(values.nullable(), (s, _cms, name) => s.select(name).options(choices(options)));
}

/** The field kinds, each with the value FormResolver gives it (src/blocks/fields.ts). */
export const f = {
	text: () => field(text(), (s, _cms, name) => s.text(name)),
	textarea: () => field(text(), (s, _cms, name) => s.textarea(name)),
	/** HTML through the CMS's allowlist. */
	richtext: () => field(text(), (s, _cms, name) => s.richtext(name)),
	number: () => field(z.number().nullable(), (s, _cms, name) => s.number(name)),
	boolean: () => field(z.boolean(), (s, _cms, name) => s.boolean(name)),
	date: () => field(text(), (s, _cms, name) => s.date(name)),
	/** One of `options` (value to label), or with `{ multiple: true }` any of them. */
	select: select,
	tags: () => field(z.array(z.string()), (s, _cms, name) => s.tags(name)),
	/** An uploaded image, as its public URL. */
	image: () => field(text(), (s, _cms, name) => s.upload(name).accept("image/*")),
	/** A page or a URL, as `{ href, type, label }`; `types` limits the choice. */
	link: (types?: LinkType[]) => field(link.nullable(), (_s, cms, name) => (types === undefined ? cms.link(name) : cms.link(name).types(types))),
	/** Live pages, as `{ id, name, url }[]`; `type` limits them to one page type. */
	pages: (type?: string) => field(z.array(pageRef), (_s, cms, name) => (type === undefined ? cms.pages(name) : cms.pages(name).of(type))),
	/**
	 * Entries of a collection (`collections` in defineSite, or a built-in by
	 * key: `"phones"`, `"socials"`): the editor picks all, the latest N,
	 * those related to the page, or by hand. `{ id, ...fields }[]`, or one
	 * entry or null with `{ single: true }`.
	 */
	collection: pick,
	/**
	 * Any other admin field: `f.custom((s, name) => s.colorpicker(name), z.string().nullable())`.
	 * `zod` is the value FormResolver gives that kind (a trimmed string or null for most).
	 */
	custom: <Out>(make: (s: S, name: string, cms: CmsFields) => unknown, zod: z.ZodType<Out>) => field(zod, (s, cms, name) => make(s, name, cms)),
	/** Rows of fields; blank rows are dropped. */
	repeater: <R extends Fields>(row: R) =>
		field(z.array(zodOf(row)) as unknown as z.ZodType<Output<R>[]>, (s, cms, name) =>
			s.repeater(name).fields(Object.entries(row).map(([key, x]) => x.build(s, cms, key))),
		),
};

/** The zod object of a field record, typed by its outputs. */
export const zodOf = <R extends Fields>(fields: R) =>
	z.object(Object.fromEntries(Object.entries(fields).map(([key, x]) => [key, x.zod]))) as unknown as z.ZodObject<{ [K in keyof R]: z.ZodType<Output<R>[K]> }>;

/** The admin form of a field record. */
export const formOf = (fields: Fields) => (s: S, cms: CmsFields) => Object.entries(fields).map(([key, x]) => x.build(s, cms, key));
