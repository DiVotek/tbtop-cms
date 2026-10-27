import type { Disk } from "@tbtop/admin";
import type { Kysely } from "kysely";
import type { SiteDef, Site } from "../define/define";
import type { CmsCacheStore } from "../support/cache";
import { firstAdminFrom, type SiteOptions, siteApp } from "./app";

export { firstAdminFrom, type Runtime, type SiteAdmin, type SiteApp, type SiteConfig, type SiteOptions, siteApp } from "./app";
export { cmsRoutes, migrateAll, type PageRenderers, renderPage } from "./routes";

export type ServeOptions<D extends SiteDef> = SiteOptions<D> & {
	// biome-ignore lint/suspicious/noExplicitAny: any Kysely instance (SQLite, Postgres, MySQL); the dialect is read from it.
	db: Kysely<any>;
	/** `memoryCache()` on one server; off without it. */
	cache?: CmsCacheStore;
	/** Where uploads go (`localDisk(…)` from `@tbtop/admin/local-disk`, S3, …); memory without it, with a warning. */
	disk?: Disk;
	/** Default `BETTER_AUTH_SECRET`; required while Better Auth is on. */
	secret?: string;
	/** Default `BETTER_AUTH_URL`; unset, Better Auth infers it from the request. */
	baseURL?: string;
};

/**
 * Node / Bun: `serve(site, { db })` is the whole site as one `fetch`, for
 * Bun.serve, Hono or a TanStack Start server entry. The first request runs
 * `ready()` (migrations, seed, the first admin from `TBTOP_ADMIN_EMAIL` /
 * `TBTOP_ADMIN_PASSWORD`); a deploy step can await it instead. A failed boot
 * is retried by the next request.
 */
export function serve<const D extends SiteDef>(site: Site<D>, options: ServeOptions<D>) {
	const { db, cache, disk, secret, baseURL, ...rest } = options;
	const firstAdmin = firstAdminFrom(typeof process === "undefined" ? {} : process.env);
	const app = siteApp(
		site,
		{
			db,
			...(cache === undefined ? {} : { cache }),
			...(disk === undefined ? {} : { disk }),
			...(secret === undefined ? {} : { secret }),
			...(baseURL === undefined ? {} : { baseURL }),
			...(firstAdmin === undefined ? {} : { firstAdmin }),
		},
		rest,
	);
	let booting: Promise<void> | null = null;
	const ready = () =>
		(booting ??= app.boot().catch((error: unknown) => {
			booting = null;
			throw error;
		}));
	return {
		/** The CMS, typed by the site's page types: `cms.site()`, `cms.loadPage()`, `cms.tasks`. */
		cms: app.cms,
		/** The Better Auth instance (null with `auth: false`), for the host's own sign-in or plugins. */
		auth: app.auth?.instance ?? null,
		users: app.users,
		/** Migrations, seed and first admin; once per process. */
		ready,
		/** Everything but pages (admin, auth, robots, sitemaps, forms); null for a page, so a framework router can render it. */
		handle: async (request: Request): Promise<Response | null> => {
			await ready();
			return app.route(request);
		},
		/** The whole site: `handle`, then pages through `render`. */
		fetch: async (request: Request): Promise<Response> => {
			await ready();
			return app.fetch(request);
		},
	};
}
