import type { S } from "@tbtop/admin";
import type { z } from "zod";
import type { BlockImpl, BlockMeta, CmsConfig, PageTypeDef, Placement, SchemaTypeDef } from "../../../src";
import type { CmsForm } from "../kit/cmsFields";
import type { AdminDefaults } from "../kit/serve";
import type { DefaultAuthOptions } from "../kit/auth";

// `@tbtop/cms/config`: identity functions and types only, so the config and
// the block files import nothing that runs on the server.

/** A block: catalog meta and server half in one place. `form` gets the CMS fields, so the file stays client-safe. */
export type BlockDef<P extends z.ZodType = z.ZodType> = Omit<BlockMeta<P>, "placements"> & {
	placements?: Placement[];
	form: CmsForm;
	resolve?: BlockImpl<z.infer<P>>["resolve"];
	toSchema?: BlockImpl<z.infer<P>>["toSchema"];
	submit?: BlockImpl<z.infer<P>>["submit"];
};
export const defineBlock = <const P extends z.ZodType>(def: BlockDef<P>) => def;

/** A page type without its key (the record key is the key). A built-in key extends that type. */
export type PageTypeEntry = Omit<PageTypeDef, "key" | "form"> & { form?: CmsForm };

export type CmsSiteConfig = {
	// biome-ignore lint/suspicious/noExplicitAny: each block keeps its own props type.
	blocks: Record<string, BlockDef<any>>;
	pageTypes?: Record<string, PageTypeEntry>;
	seo?: {
		/** Schema types by key: `Service: (ctx) => [node]`. */
		schemaTypes?: Record<string, SchemaTypeDef["build"] | Omit<SchemaTypeDef, "key">>;
		extend?: NonNullable<CmsConfig["seo"]>["extend"];
	};
	settings?: { site?: { label: string; fields: CmsForm }[]; secret?: string[] };
	/** Better Auth from `db`; false: the host passes `authorize` and its own session. */
	auth?: Omit<DefaultAuthOptions, "secret" | "baseURL" | "database"> | false;
	admin?: Omit<AdminDefaults, "disks">;
	/** Everything else of the core config, as today (forms, notifications, preview, timezone, …). */
	core?: Omit<CmsConfig, "db" | "blocks" | "pageTypes" | "schemaTypes" | "seo" | "siteSettings" | "auth">;
};

export type PageDefs<R> = { [K in keyof R & string]: Omit<R[K], "form"> & { key: K; form?: (s: S) => unknown[] } }[keyof R & string][];

export const defineConfig = <const C extends CmsSiteConfig>(config: C) => config;

