import type { S } from "@tbtop/admin";
import type { z } from "zod";
import type { BlockMeta, CmsConfig, PageTypeDef, SchemaContext } from "../../../src";
import type { CmsForm } from "../kit/cmsFields";
import type { BlockDef, CmsSiteConfig, PageTypeEntry } from "../v2-config/define";

/**
 * V4 · Fluent builder (Elysia / tRPC style). Each call returns a new builder
 * whose type has grown by what it added, so `catalog`, block props and
 * `page.fields` are typed without a const tuple or a satisfies. The builder
 * is definitions only (client-safe): runtime helpers come in through the
 * callbacks' arguments, never by import. `createCms(site, runtime)` on the
 * server, `cloudflare(site)` on Workers.
 */

/** What a schema type or SEO extension gets besides the context: the helpers it would otherwise import from the server entry. */
export type SeoHelpers = { baseNode(type: string): Record<string, unknown>; identityId: string };

export type SeoExtend = (nodes: Record<string, unknown>[], ctx: SchemaContext, h: SeoHelpers) => Record<string, unknown>[] | Promise<Record<string, unknown>[]>;

type PageDefOf<K extends string, D> = Omit<D, "form"> & { key: K; form?: (s: S) => unknown[] };

export class SiteBuilder<B extends Record<string, BlockDef<z.ZodType>> = {}, PT extends PageTypeDef = never> {
	private constructor(readonly config: CmsSiteConfig & { blocks: B; extenders?: SeoExtend[] }) {}

	static create(): SiteBuilder {
		return new SiteBuilder({ blocks: {} });
	}

	private with<B2 extends Record<string, BlockDef<z.ZodType>>, PT2 extends PageTypeDef>(patch: Partial<CmsSiteConfig>): SiteBuilder<B2, PT2> {
		return new SiteBuilder<B2, PT2>({ ...this.config, ...patch } as CmsSiteConfig & { blocks: B2 });
	}

	block<const K extends string, P extends z.ZodType>(kind: K, def: BlockDef<P>): SiteBuilder<B & { [k in K]: BlockDef<P> }, PT> {
		if (kind in this.config.blocks) throw new Error(`Block [${kind}] is already defined.`);
		return this.with({ blocks: { ...this.config.blocks, [kind]: def } });
	}

	/** A new page type, or a built-in key (`page`, `category`, `article`) to extend it. */
	pageType<const K extends string, const D extends PageTypeEntry>(key: K, def: D): SiteBuilder<B, PT | PageDefOf<K, D>> {
		if (key in (this.config.pageTypes ?? {})) throw new Error(`Page type [${key}] is already defined.`);
		return this.with({ pageTypes: { ...this.config.pageTypes, [key]: def } });
	}

	schemaType(key: string, build: (ctx: SchemaContext, h: SeoHelpers) => Record<string, unknown>[]): SiteBuilder<B, PT> {
		return this.with({ seo: { ...this.config.seo, schemaTypes: { ...this.config.seo?.schemaTypes, [key]: build as never } } });
	}

	/** The page's JSON-LD, last; chained: a second call runs after the first. */
	seo(extend: SeoExtend): SiteBuilder<B, PT> {
		return this.with({ extenders: [...(this.config.extenders ?? []), extend] } as Partial<CmsSiteConfig>);
	}

	settingsTab(label: string, fields: CmsForm): SiteBuilder<B, PT> {
		return this.with({ settings: { ...this.config.settings, site: [...(this.config.settings?.site ?? []), { label, fields }] } });
	}

	core(options: NonNullable<CmsSiteConfig["core"]>): SiteBuilder<B, PT> {
		return this.with({ core: { ...this.config.core, ...options } as CmsSiteConfig["core"] });
	}

	auth(options: CmsSiteConfig["auth"]): SiteBuilder<B, PT> {
		return this.with({ auth: options });
	}

	/** The json-render catalog for the browser: `defineRegistry(cmsCatalog(site.catalog), …)`. */
	get catalog(): { [K in keyof B]: BlockMeta<B[K]["props"]> } {
		return Object.fromEntries(Object.entries(this.config.blocks).map(([k, b]) => [k, { props: b.props, label: b.label, description: b.description, category: b.category, placements: b.placements }])) as never;
	}

	/** Type-only: the page type defs, for `Cms<…>` and `PageProps<…>`. */
	declare readonly pageTypes: PT[];
}

export const tbtop = () => SiteBuilder.create();
export type { CmsConfig };
