import type { Disk } from "@tbtop/admin";
import type { CmsCacheStore, CmsConfig, PageTypeDef, SchemaTypeDef } from "../../../src";
import type { CmsAccessRole, DefaultAuth, DefaultAuthOptions } from "../kit/auth";
import type { BlockDef } from "../v2-config/define";
import type { CmsForm } from "../kit/cmsFields";

// `@tbtop/cms/plugin`: types and identity functions only (client-safe).

/** Bindings a plugin can contribute (the cloudflare plugin gives cache, disk and the auth database). */
export type Runtime = { cache?: CmsCacheStore; disk?: Disk; secret?: string; baseURL?: string; authDatabase?: DefaultAuthOptions["database"] };

/**
 * V3 · Plugins (Better Auth / Vite style). Every extension point is a plugin
 * field; the site's own code is a plugin too, and so are auth, Cloudflare and
 * a third-party "shop" package. Order matters only for `seo.extend` (chained)
 * and duplicate keys (an error).
 */
export type CmsPlugin<PT extends readonly PageTypeDef[] = readonly PageTypeDef[], API = unknown> = {
	name: string;
	// biome-ignore lint/suspicious/noExplicitAny: each block keeps its own props type.
	blocks?: Record<string, BlockDef<any>>;
	pageTypes?: PT;
	schemaTypes?: SchemaTypeDef[];
	seo?: { extend?: NonNullable<CmsConfig["seo"]>["extend"] };
	settings?: { site?: { label: string; fields: CmsForm }[]; secret?: string[] };
	runtime?: Runtime;
	/** The one auth plugin: builds the adapter from the db and the merged runtime. */
	auth?: (db: import("kysely").Kysely<unknown>, runtime: Runtime) => DefaultAuth;
	/** Runs once the CMS exists: what the plugin adds to it (`api`), its routes, its migrations. */
	setup?: (ctx: { cms: import("../../../src").Cms; auth: DefaultAuth | null }) => {
		api?: API;
		route?: (request: Request) => Promise<Response | null>;
		migrate?: () => Promise<void>;
	};
	/** Plain core options (forms, notifications, preview, timezone…). */
	core?: Partial<Omit<CmsConfig, "db" | "blocks" | "pageTypes" | "schemaTypes" | "seo" | "siteSettings" | "auth">>;
};

export const definePlugin = <const PT extends readonly PageTypeDef[] = [], API = unknown>(plugin: CmsPlugin<PT, API>) => plugin;

export type { CmsAccessRole };
