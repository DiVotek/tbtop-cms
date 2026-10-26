import type { S } from "@tbtop/admin";
import { z } from "zod";
import type { CmsFields } from "../kit/cmsFields";

/**
 * V5 · fields declared once. Each `f.*` knows its admin field (built when
 * the form is asked for, so this file imports nothing from the server) and
 * the value FormResolver produces for its kind, as zod. So a block's props
 * and a page type's `fields` come out of the form: no separate zod schema
 * that has to repeat the field names (today createCms throws when they
 * drift). The kinds and outputs mirror src/blocks/fields.ts.
 */
export class F<Out> {
	constructor(
		readonly zod: z.ZodType<Out>,
		private readonly make: (s: S, f: CmsFields, name: string) => FieldLike,
		private readonly steps: ((field: FieldLike) => FieldLike)[] = [],
	) {}

	private step(fn: (field: FieldLike) => FieldLike): F<Out> {
		return new F(this.zod, this.make, [...this.steps, fn]);
	}

	label(label: string): F<Out> {
		return this.step((field) => field.label(label));
	}

	/** Stored per locale; the resolver hands the page's locale (default as fallback), so the output type stays the same. */
	translatable(): F<Out> {
		return this.step((field) => field.translatable());
	}

	/** Editor-side required; the output type keeps its null (an older row may still be blank). */
	required(): F<Out> {
		return this.step((field) => field.required());
	}

	build(s: S, f: CmsFields, name: string): unknown {
		return this.steps.reduce((field, step) => step(field), this.make(s, f, name));
	}
}

type FieldLike = { label(label: string): FieldLike; translatable(): FieldLike; required(): FieldLike };
const field = <Out>(zod: z.ZodType<Out>, make: (s: S, f: CmsFields, name: string) => unknown) => new F<Out>(zod, make as never);

export type Fields = Record<string, F<unknown>>;
export type Output<R extends Fields> = { [K in keyof R]: R[K] extends F<infer O> ? O : never };

const link = z.object({ href: z.string(), type: z.string(), label: z.string() });
const pageRef = z.object({ id: z.string(), name: z.string(), url: z.string() });

export const f = {
	text: () => field(z.string().nullable(), (s, _f, name) => s.text(name)),
	textarea: () => field(z.string().nullable(), (s, _f, name) => s.textarea(name)),
	richtext: () => field(z.string().nullable(), (s, _f, name) => s.richtext(name)),
	number: () => field(z.number().nullable(), (s, _f, name) => s.number(name)),
	boolean: () => field(z.boolean(), (s, _f, name) => s.boolean(name)),
	image: () => field(z.string().nullable(), (s, _f, name) => s.upload(name).accept("image/*")),
	link: () => field(link.nullable(), (_s, f, name) => f.link(name)),
	pages: (type?: string) => field(z.array(pageRef), (_s, f, name) => (type === undefined ? f.pages(name) : f.pages(name).of(type))),
	repeater: <R extends Fields>(row: R) =>
		field(z.array(z.object(mapFields(row, (x) => x.zod)) as unknown as z.ZodType<Output<R>>), (s, f, name) =>
			s.repeater(name).fields(Object.entries(row).map(([key, x]) => x.build(s, f, key))),
		),
};

export const mapFields = <R extends Fields, V>(fields: R, fn: (field: F<unknown>, key: string) => V) =>
	Object.fromEntries(Object.entries(fields).map(([key, x]) => [key, fn(x, key)])) as { [K in keyof R]: V };

/** The zod object of a field record, typed by its outputs. */
export const zodOf = <R extends Fields>(fields: R) => z.object(mapFields(fields, (x) => x.zod)) as unknown as z.ZodObject<{ [K in keyof R]: z.ZodType<Output<R>[K]> }>;
export const formOf = (fields: Fields) => (s: S, cms: CmsFields) => Object.entries(fields).map(([key, x]) => x.build(s, cms, key));
