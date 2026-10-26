import type { BlockImpl, PageTypeDef, Placement, SchemaContext } from "../../../src";
import type { CmsSiteConfig } from "../v2-config/define";
import type { SeoExtend, SeoHelpers } from "../v4-builder/define";
import { type Fields, f, formOf, mapFields, type Output, zodOf } from "./fields";

export { f };

/**
 * V5 · Zero glue. A site is records of fields: a block IS its fields (props
 * typed from them), a page type IS its fields, settings tabs ARE fields. No
 * key repeated, no zod next to a form, no imports from the server side, so the
 * browser imports this same file for the block catalog. The runtime entries
 * (`serve`, `cloudflare`) take the site plus the database and nothing else.
 */

type BlockOptions<P> = { label?: string; description?: string; category?: string; placements?: Placement[] } & Pick<BlockImpl<P>, "resolve" | "toSchema" | "submit">;
export type Block<R extends Fields = Fields> = { fields: R; options: BlockOptions<Output<R>> };
export const block = <R extends Fields>(fields: R, options: BlockOptions<Output<R>> = {}): Block<R> => ({ fields, options });

type PageOptions = Omit<PageTypeDef, "key" | "form" | "schema" | "schemaTypes"> & { jsonLd?: string | string[] };
export type PageType<R extends Fields = Fields> = { fields: R; options: PageOptions };
export const pageType = <R extends Fields>(fields: R, options: PageOptions = {}): PageType<R> => ({ fields, options });

export type SiteDef = {
	// biome-ignore lint/suspicious/noExplicitAny: each block keeps its own props type.
	blocks?: Record<string, Block<any>>;
	/** A built-in key (`page`, `category`, `article`) extends it. A bare record is the fields. */
	pages?: Record<string, PageType<Fields> | Fields>;
	/** JSON-LD types by key. */
	jsonLd?: Record<string, (ctx: SchemaContext, h: SeoHelpers) => Record<string, unknown>[]>;
	/** The page's JSON-LD, last. */
	seo?: SeoExtend;
	/** Site settings tabs by label; values at `site.<field>`. */
	settings?: Record<string, Fields>;
	auth?: CmsSiteConfig["auth"];
	core?: CmsSiteConfig["core"];
};

const isPageType = (value: PageType<Fields> | Fields): value is PageType<Fields> => "fields" in value && "options" in value && !(value.fields instanceof Object && "zod" in value.fields);
const fieldsOf = (value: PageType<Fields> | Fields): Fields => (isPageType(value) ? value.fields : value);

type PageFields<V> = V extends PageType<infer R> ? R : V extends Fields ? V : never;
type PageDefs<P> = { [K in keyof P & string]: { key: K; schema: ReturnType<typeof zodOf<PageFields<P[K]>>> } }[keyof P & string];

export type Site<D extends SiteDef = SiteDef> = {
	def: D;
	/** The json-render catalog for the browser. */
	catalog: { [K in keyof D["blocks"]]: { props: ReturnType<typeof zodOf<D["blocks"][K] extends Block<infer R> ? R : never>> } };
	/** Type-only: page type defs, for Cms<…> and PageProps<…>. */
	pageTypes: PageDefs<NonNullable<D["pages"]>>[];
	/** The V2/V4 config these records stand for (what the server entry consumes). */
	config: CmsSiteConfig & { extenders: SeoExtend[] };
};

export function defineSite<const D extends SiteDef>(def: D): Site<D> {
	const blocks = Object.entries(def.blocks ?? {});
	return {
		def,
		pageTypes: [],
		catalog: Object.fromEntries(blocks.map(([kind, b]) => [kind, { props: zodOf(b.fields), ...b.options }])) as never,
		config: {
			blocks: Object.fromEntries(blocks.map(([kind, b]) => [kind, { ...b.options, props: zodOf(b.fields), form: formOf(b.fields) }])),
			pageTypes: Object.fromEntries(
				Object.entries(def.pages ?? {}).map(([key, value]) => {
					const options = isPageType(value) ? value.options : {};
					const { jsonLd, ...rest } = options as PageOptions;
					const fields = fieldsOf(value);
					return [key, { ...rest, ...(jsonLd === undefined ? {} : { schemaTypes: [jsonLd].flat() }), form: formOf(fields), schema: zodOf(fields) }];
				}),
			),
			seo: { schemaTypes: (def.jsonLd ?? {}) as never },
			settings: { site: Object.entries(def.settings ?? {}).map(([label, fields]) => ({ label, fields: formOf(fields) })) },
			...(def.auth === undefined ? {} : { auth: def.auth }),
			...(def.core === undefined ? {} : { core: def.core }),
			extenders: def.seo === undefined ? [] : [def.seo],
		},
	};
}

export { mapFields };

/**
 * `@tbtop/cms/blocks` in V5 terms: the built-in Hero is fields too. Props ARE
 * the fields here, so the CTA is one `f.link` (`{ href, type, label }`)
 * instead of kit/hero.ts's ctaLabel/ctaHref inputs reshaped by `resolve`.
 */
export const hero = block(
	{
		title: f.text().label("Title").translatable().required(),
		subtitle: f.textarea().label("Subtitle").translatable(),
		image: f.image().label("Image"),
		cta: f.link().label("CTA"),
	},
	{ label: "Hero", category: "content", description: "Full-width intro section." },
);
