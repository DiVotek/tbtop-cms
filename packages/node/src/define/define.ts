import { z } from "zod";
import type { Placement } from "../blocks/catalog";
import type { BlockImpl } from "../blocks/registry";
import type { CollectionDef } from "../content/collections";
import type { PageTypeDef } from "../content/pageTypes";
import type { SchemaContext } from "../seo/schemaContext";
import { type CmsFields, F, f, type Fields, formOf, type Output, zodOf } from "./fields";
import { type CollectionType, nameCollections } from "./collections";

/** What every JSON-LD callback gets besides the context: the page's base node and the site's identity @id. */
export type SeoHelpers = {
	/** The page's WebPage-family node (`@type`, name, url, dates) for `type`. */
	baseNode(type: string): Record<string, unknown>;
	/** The @id that Article author and publisher point to: give your Organization or Person this @id. */
	identityId: string;
};

/** The page's JSON-LD documents, last: return them with yours added or replaced. */
export type SeoExtend = (nodes: Record<string, unknown>[], ctx: SchemaContext, h: SeoHelpers) => Record<string, unknown>[] | Promise<Record<string, unknown>[]>;

type BlockOptions<P> = {
	label?: string;
	description?: string;
	category?: string;
	/** Where the block may go; default `["pages"]`. */
	placements?: Placement[];
} & Pick<BlockImpl<P>, "resolve" | "toSchema">;

type FormBlockOptions<P> = BlockOptions<P> & Required<Pick<BlockImpl<P>, "submit">> & Pick<BlockImpl<P>, "formName">;

/** What a form block's props carry besides its fields: where it posts, and the honeypot input's name. */
export type FormProps = { action: string; honeypotField?: string };

/** A block is its fields: the props are what they resolve to (plus FormProps for a form block). */
export type Block<R extends Fields = Fields, Form extends boolean = boolean> = {
	readonly fields: R;
	readonly options: Form extends true ? FormBlockOptions<Output<R>> : BlockOptions<Output<R>>;
	readonly form: Form;
};

/** `block({ title: f.text() }, { label: "Promo" })` */
export const block = <const R extends Fields>(fields: R, options: BlockOptions<Output<R>> = {}): Block<R, false> => ({ fields, options, form: false });

/** A block that posts: `submit` takes the validated payload (`submit.schema`), the CMS binds `action` into its props. */
export const formBlock = <const R extends Fields>(fields: R, options: FormBlockOptions<Output<R>>): Block<R, true> => ({ fields, options, form: true });

const formProps = { action: z.string(), honeypotField: z.string().optional() };

/** A block's props as zod. */
// biome-ignore lint/suspicious/noExplicitAny: any block.
export const blockProps = (b: Block<any>) => (b.form ? zodOf(b.fields).extend(formProps) : zodOf(b.fields));

type PageOptions = Omit<PageTypeDef, "key" | "form" | "schema" | "schemaTypes" | "resolveFields"> & {
	/** JSON-LD type keys the editor may pick (the site's `jsonLd` or the built-in ones); the first is the default. */
	jsonLd?: string | readonly string[];
};

/** A page type is its fields plus the tree rules; a bare field record in `pages` is the same with no options. */
export type PageType<R extends Fields = Fields> = { readonly fields: R; readonly options: PageOptions };

/** `pageType({ price: f.number() }, { label: "Service", jsonLd: "Service" })` */
export const pageType = <const R extends Fields>(fields: R, options: PageOptions = {}): PageType<R> => ({ fields, options });

export type SiteDef = {
	// biome-ignore lint/suspicious/noExplicitAny: each block keeps its own props type.
	blocks?: Record<string, Block<any>>;
	/** Page types by key; a built-in key (`page`, `category`, `article`) adds fields to that type. */
	pages?: Record<string, PageType | Fields>;
	/** JSON-LD types by key, offered to the page types that list them in `jsonLd`. */
	jsonLd?: Record<string, (ctx: SchemaContext, h: SeoHelpers) => Record<string, unknown>[]>;
	/** Every page's JSON-LD, last. */
	seo?: SeoExtend;
	/** Collections by key: records with no page of their own, picked in blocks with `f.collection()`. */
	// biome-ignore lint/suspicious/noExplicitAny: each collection keeps its own fields type.
	collections?: Record<string, CollectionType<any>>;
	/** Site settings tabs by label; a field's value is at `site.<name>` in the settings. */
	settings?: Record<string, Fields>;
};

/** A field record may itself hold fields named `fields` and `options`; a PageType holds a record there. */
const isPageType = (value: PageType | Fields): value is PageType => "fields" in value && "options" in value && !(value.fields instanceof F) && !(value.options instanceof F);

type PageFields<V> = V extends PageType<infer R> ? R : V extends Fields ? V : never;

/** The page types of a site as createCms types them: `page.fields` per key. */
export type SitePageTypes<D extends SiteDef> = {
	[K in keyof NonNullable<D["pages"]> & string]: { key: K; schema: ReturnType<typeof zodOf<PageFields<NonNullable<D["pages"]>[K]>>> };
}[keyof NonNullable<D["pages"]> & string][];

/** The json-render catalog of a site's blocks. */
type PropsSchema<B> = B extends Block<infer R, infer Form> ? (Form extends true ? z.ZodType<Output<R> & FormProps> : z.ZodType<Output<R>>) : never;

export type SiteCatalog<D extends SiteDef> = {
	[K in keyof NonNullable<D["blocks"]>]: { props: PropsSchema<NonNullable<D["blocks"]>[K]> } & Omit<BlockOptions<unknown>, "resolve" | "toSchema">;
};

export type Site<D extends SiteDef = SiteDef> = {
	readonly def: D;
	/** The blocks' json-render catalog, for the browser's registry (`defineRegistry(site.catalog, …)`). */
	readonly catalog: SiteCatalog<D>;
	/** Type only: the page types, for `Cms<…>` and `PageProps<…>`. */
	readonly pageTypes: SitePageTypes<D>;
};

/** A site's collections as createCms takes them: fields to `form`. */
export function collectionDefs(def: SiteDef, cms: CmsFields): CollectionDef[] {
	return Object.entries(def.collections ?? {}).map(([key, { fields, options }]) => ({ ...options, key, form: (s) => formOf(fields)(s, cms) }));
}

/** A site's page types as createCms takes them: fields to `form` and `schema`, `jsonLd` to `schemaTypes`. */
export function pageTypeDefs(def: SiteDef, cms: CmsFields): PageTypeDef[] {
	return Object.entries(def.pages ?? {}).map(([key, value]) => {
		const fields = isPageType(value) ? value.fields : value;
		const { jsonLd, ...options } = isPageType(value) ? value.options : ({} as PageOptions);
		return {
			...options,
			key,
			...(jsonLd === undefined ? {} : { schemaTypes: [jsonLd].flat() }),
			form: (s) => formOf(fields)(s, cms),
			schema: zodOf(fields) as z.ZodObject,
		};
	});
}

/**
 * The whole site as records of fields. Client-safe: no server import, so the
 * browser takes `site.catalog` from the same file. `serve(site, { db })` and
 * `cloudflare(site)` run it.
 */
export function defineSite<const D extends SiteDef>(def: D): Site<D> {
	nameCollections(def.collections ?? {});
	const catalog = Object.fromEntries(
		Object.entries(def.blocks ?? {}).map(([kind, b]) => {
			const { label, description, category, placements } = b.options;
			return [kind, { label, description, category, placements, props: blockProps(b) }];
		}),
	);
	return { def, catalog: catalog as unknown as SiteCatalog<D>, pageTypes: [] as unknown as SitePageTypes<D> };
}

/**
 * The opt-in Hero as fields. Props are the fields, so its CTA is one link
 * (`{ href, type, label }`); `@tbtop/cms/react`'s Hero renders it.
 */
export const hero = block(
	{
		title: f.text().label("Title").translatable().required(),
		subtitle: f.textarea().label("Subtitle").translatable(),
		image: f.image().label("Image"),
		cta: f.link(["page", "external"]).label("Button"),
	},
	{ label: "Hero", category: "content", description: "Full-width intro: a title, a line of text, an image and a button." },
);
