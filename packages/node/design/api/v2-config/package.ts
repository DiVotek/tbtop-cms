import type { Disk } from "@tbtop/admin";
import type { Kysely } from "kysely";
import {
	type BlockImpl,
	type BlockMeta,
	type Cms,
	type CmsCacheStore,
	type CmsDatabase,
	createCms as createCore,
	implementBlocks,
	type PageTypeDef,
	type SchemaContext,
} from "../../../src";
import type { ResolveContext } from "../../../src/blocks/context";
import { type DefaultAuthOptions, defaultAuth } from "../kit/auth";
import { withCmsFields } from "../kit/cmsFields";
import type { CmsSiteConfig, PageDefs } from "./define";

export { defineBlock, defineConfig } from "./define";
import { cmsRoutes, createCmsAdmin, migrateAll } from "../kit/serve";

/**
 * V2 · One config object (Payload/Keystatic style). Everything the site IS
 * goes in one `defineConfig` (the CLI reads the same file); what it RUNS ON
 * (db, cache, disk, secrets) comes at runtime. Extension points are records
 * keyed by name, so a key is never repeated inside its value.
 */

export type Runtime = {
	db: Kysely<unknown>;
	cache?: CmsCacheStore;
	disk?: Disk;
	/** Default `BETTER_AUTH_SECRET` / `BETTER_AUTH_URL` from the environment. */
	secret?: string;
	baseURL?: string;
	/** Better Auth's own database handle (D1: the binding). */
	authDatabase?: DefaultAuthOptions["database"];
};

export function createCms<const C extends CmsSiteConfig>(config: C, runtime: Runtime) {
	type T = PageDefs<NonNullable<C["pageTypes"]>>;
	const { db } = runtime;
	const auth =
		config.auth === false
			? null
			: defaultAuth(db, {
					...config.auth,
					...(runtime.secret === undefined ? {} : { secret: runtime.secret }),
					...(runtime.baseURL === undefined ? {} : { baseURL: runtime.baseURL }),
					...(runtime.authDatabase === undefined ? {} : { database: runtime.authDatabase }),
				});
	const blocks = Object.entries(config.blocks);
	const catalog = Object.fromEntries(blocks.map(([kind, b]) => [kind, { props: b.props, label: b.label, description: b.description, category: b.category, placements: b.placements }]));
	const impls = Object.fromEntries(
		blocks.map(([kind, b]) => [kind, { form: withCmsFields(b.form), resolve: b.resolve, toSchema: b.toSchema, submit: b.submit } as BlockImpl]),
	);
	const pageTypes = Object.entries(config.pageTypes ?? {}).map(([key, def]) => ({ ...def, key, form: def.form === undefined ? undefined : withCmsFields(def.form) }));
	const schemaTypes = Object.entries(config.seo?.schemaTypes ?? {}).map(([key, def]) => (typeof def === "function" ? { key, build: def } : { ...def, key }));
	const core = createCore({
		...config.core,
		db: db as Kysely<CmsDatabase>,
		blocks: implementBlocks(catalog as Record<string, BlockMeta>, impls),
		pageTypes: pageTypes as PageTypeDef[],
		schemaTypes,
		...(config.seo?.extend === undefined ? {} : { seo: { extend: config.seo.extend } }),
		...(config.settings?.site === undefined ? {} : { siteSettings: { tabs: (s) => (config.settings?.site ?? []).map((tab) => ({ label: tab.label, children: withCmsFields(tab.fields)(s) })) } }),
		...(config.settings?.secret === undefined ? {} : { secretSettings: config.settings.secret }),
		...(auth === null ? {} : { auth: auth.adapter }),
		...(runtime.cache === undefined ? {} : { cache: { store: runtime.cache } }),
	}) as unknown as Cms<T>;
	const admin = createCmsAdmin(core, { ...config.admin, ...(runtime.disk === undefined ? {} : { disks: { public: runtime.disk } }) });
	const routes = cmsRoutes(core, admin, auth);
	return {
		...core,
		/** Admin, Better Auth, robots, sitemaps, form posts; null for a page request. */
		route: routes,
		/** The CMS's tables and Better Auth's. Idempotent. */
		migrate: () => migrateAll(db, auth),
		users: { ensure: (email: string, role: string, options: { password: string; name?: string }) => (auth === null ? Promise.reject(new Error("auth: false")) : auth.ensureUser(email, role, options)) },
	};
}

export type { ResolveContext, SchemaContext };
