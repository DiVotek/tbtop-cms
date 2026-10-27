import type { Admin, Disk } from "@tbtop/admin";
import { kyselyRules } from "@tbtop/admin/kysely";
import type { Kysely } from "kysely";
import { type CmsAdminOverrides, createCmsAdmin } from "../admin/create";
import { type DefaultAuth, type DefaultAuthOptions, defaultAuth, ensureFirstAdmin } from "../betterAuth/defaultAuth";
import type { Catalog } from "../blocks/catalog";
import { linkField, pagesField } from "../blocks/cmsFields";
import { type BlockImpl, implementBlocks } from "../blocks/registry";
import { type Cms, createCms } from "../cms";
import type { CmsConfig } from "../config";
import type { CmsDatabase } from "../db/schema";
import { ensureErrorPages } from "../frontend/errorPage";
import type { PageProps } from "../frontend/load";
import { type Fixture, seedFixtures } from "../fixtures/seed";
import { baseNode, identityId, type SchemaContext } from "../seo/schemaContext";
import { pageTypeDefs, type SeoHelpers, type Site, type SiteDef, type SitePageTypes } from "../define/define";
import { type CmsFields, formOf } from "../define/fields";
import type { CmsCacheStore } from "../support/cache";
import { cmsRoutes, migrateAll, type PageRenderers, renderPage } from "./routes";

/** The CMS config a site file does not hold: url, preview, forms, notifications, timezone and the rest. */
export type SiteConfig = Omit<CmsConfig, "db" | "blocks" | "pageTypes" | "schemaTypes" | "seo" | "siteSettings" | "cache">;

/** What `serve` and `cloudflare` take besides the site and what it runs on. */
export type SiteOptions<D extends SiteDef> = Partial<PageRenderers<PageProps<SitePageTypes<D>>>> & {
	/** Seeded once, into an empty site. */
	seed?: Fixture;
	config?: SiteConfig;
	/** Better Auth's options past the defaults; false: no Better Auth (the host gives `config.auth` or `config.authorize`). */
	auth?: false | Omit<DefaultAuthOptions, "secret" | "baseURL" | "database">;
	/**
	 * The admin panel past the CMS's: the host's pages (array appended,
	 * function filters), rootView, any createAdmin option. A function gets
	 * the CMS, for pages that read it (on Workers there is no `db` before the request).
	 */
	admin?: SiteAdmin<D>;
};

type AdminOverrides = Omit<CmsAdminOverrides, "disks">;
export type SiteAdmin<D extends SiteDef> = AdminOverrides | ((cms: Cms<SitePageTypes<D>>) => AdminOverrides);

/** What a site runs on: the database and, where the platform has them, a cache, a disk and secrets. */
export type Runtime = {
	// biome-ignore lint/suspicious/noExplicitAny: any Kysely instance; the CMS types its own tables.
	db: Kysely<any>;
	cache?: CmsCacheStore;
	/** The admin's `public` disk (uploads). */
	disk?: Disk;
	secret?: string;
	baseURL?: string;
	/** Better Auth's own database handle (D1: the binding). */
	authDatabase?: DefaultAuthOptions["database"];
	/** Created on boot when missing (`TBTOP_ADMIN_EMAIL` / `TBTOP_ADMIN_PASSWORD`). */
	firstAdmin?: { email: string; password: string };
};

const cmsFields = { link: linkField, pages: pagesField } as unknown as CmsFields;

const helpers = (ctx: SchemaContext): SeoHelpers => ({ baseNode: (type) => baseNode(ctx, type), identityId: identityId(ctx.site) });

/** The admin user from secrets: both or none. */
export function firstAdminFrom(source: Record<string, unknown>): { email: string; password: string } | undefined {
	const email = source.TBTOP_ADMIN_EMAIL;
	const password = source.TBTOP_ADMIN_PASSWORD;
	return typeof email === "string" && email !== "" && typeof password === "string" && password !== "" ? { email, password } : undefined;
}

/**
 * A site on its runtime, built from the low-level API: createCms with the
 * site's blocks, page types, JSON-LD and settings; Better Auth from `db`;
 * the admin panel; one route handler; migrations and the seed as `boot`.
 */
export function siteApp<const D extends SiteDef>(site: Site<D>, runtime: Runtime, options: SiteOptions<D> = {}): SiteApp<D> {
	const { def } = site;
	const { db } = runtime;
	const config = options.config ?? {};
	const auth: DefaultAuth | null =
		options.auth === false || config.auth !== undefined
			? null
			: defaultAuth(db, {
					...options.auth,
					...(runtime.secret === undefined ? {} : { secret: runtime.secret }),
					...(runtime.baseURL === undefined ? {} : { baseURL: runtime.baseURL }),
					...(runtime.authDatabase === undefined ? {} : { database: runtime.authDatabase }),
				});
	const impls = Object.fromEntries(
		Object.entries(def.blocks ?? {}).map(([kind, b]) => [kind, { ...b.options, form: (s) => formOf(b.fields)(s, cmsFields) } as BlockImpl]),
	);
	const extend = def.seo;
	const settings = def.settings;
	const cms = createCms({
		hostRules: kyselyRules(db),
		...config,
		db: db as Kysely<CmsDatabase>,
		blocks: implementBlocks(site.catalog as Catalog, impls),
		pageTypes: pageTypeDefs(def, cmsFields),
		schemaTypes: Object.entries(def.jsonLd ?? {}).map(([key, build]) => ({ key, build: (ctx: SchemaContext) => build(ctx, helpers(ctx)) })),
		...(extend === undefined ? {} : { seo: { extend: (nodes, ctx) => extend(nodes, ctx, helpers(ctx)) } }),
		...(settings === undefined
			? {}
			: { siteSettings: { tabs: (s) => Object.entries(settings).map(([label, fields]) => ({ label, children: formOf(fields)(s, cmsFields) })) } }),
		...(auth === null ? {} : { auth: auth.adapter }),
		...(runtime.cache === undefined ? {} : { cache: { store: runtime.cache } }),
	}) as unknown as Cms<SitePageTypes<D>>;
	const adminOptions = typeof options.admin === "function" ? options.admin(cms) : options.admin;
	const admin = createCmsAdmin(cms, { ...adminOptions, ...(runtime.disk === undefined ? {} : { disks: { public: runtime.disk } }) });
	const route = cmsRoutes(cms, admin, auth);
	const migrate = () => migrateAll(db, auth);
	const { render, renderError } = options;
	/** The seed into an empty site, the 404/5xx pages and the first admin, on migrated tables. Idempotent. */
	const setup = async () => {
		if (options.seed !== undefined) {
			await seedFixtures(cms, options.seed);
		}
		await ensureErrorPages(cms);
		if (auth !== null && runtime.firstAdmin !== undefined) {
			await ensureFirstAdmin(auth, runtime.firstAdmin.email, runtime.firstAdmin.password);
		}
	};
	return {
		cms,
		/** The Better Auth the site built; null with `auth: false`. */
		auth,
		admin,
		/** Admin, Better Auth, robots, sitemaps, form posts; null for a page request. */
		route,
		/** The CMS's tables and Better Auth's. Idempotent. */
		migrate,
		/** `ensureUser` of the site's Better Auth: a seed, a test or a CLI, never request input; never the `admin` role. */
		users: {
			ensure: (email: string, role: string, opts: { password: string; name?: string }) =>
				auth === null ? Promise.reject(new Error("tbtop/cms: the site has no Better Auth (auth: false).")) : auth.ensureUser(email, role, opts),
		},
		/** Migrations, then `setup`. Idempotent. */
		boot: async () => {
			await migrate();
			await setup();
		},
		setup,
		/** The whole site as one fetch: routes, then pages through `render` (404 without one). */
		fetch: async (request: Request, waitUntil?: (task: Promise<unknown>) => void): Promise<Response> => {
			const routed = await route(request);
			if (routed !== null) {
				return routed;
			}
			if (render === undefined) {
				return new Response("Not found", { status: 404 });
			}
			return renderPage(cms, request, { render, ...(renderError === undefined ? {} : { renderError }) }, waitUntil);
		},
	};
}

/** A site on its runtime: what `serve` and `cloudflare` wrap. */
export type SiteApp<D extends SiteDef = SiteDef> = {
	cms: Cms<SitePageTypes<D>>;
	/** The Better Auth the site built; null with `auth: false`. */
	auth: DefaultAuth | null;
	admin: Admin;
	/** Admin, Better Auth, robots, sitemaps, form posts; null for a page request. */
	route(request: Request): Promise<Response | null>;
	/** The CMS's tables and Better Auth's. Idempotent. */
	migrate(): Promise<void>;
	users: { ensure(email: string, role: string, options: { password: string; name?: string }): Promise<string> };
	/** Migrations, then `setup`. Idempotent. */
	boot(): Promise<void>;
	/** The seed into an empty site, the 404/5xx pages and the first admin, on tables already migrated (Workers: the D1 files). Idempotent. */
	setup(): Promise<void>;
	/** The whole site as one fetch: routes, then pages through `render` (404 without one). */
	fetch(request: Request, waitUntil?: (task: Promise<unknown>) => void): Promise<Response>;
};
