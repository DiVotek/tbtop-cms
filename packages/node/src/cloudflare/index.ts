import { r2Disk } from "@tbtop/admin";
import { firstAdminFrom, type Runtime, type SiteOptions, siteApp } from "../serve/app";
import type { Site, SiteDef } from "../define/define";
import { kvCache } from "../support/cache";
import { d1Kysely } from "./d1";
import type { CloudflareEnv } from "./types";
import { workerEntry } from "./worker";

export { D1Dialect, d1Kysely } from "./d1";
export type { CloudflareEnv, D1Database, D1PreparedStatement, D1Result, ExecutionContext, ScheduledController } from "./types";
export { type WorkerApp, workerEntry } from "./worker";

/**
 * What a site runs on, from the Worker's bindings by name. Better Auth gets
 * the D1 binding itself: it has its own D1 dialect.
 */
export function runtimeFromEnv(env: CloudflareEnv): Runtime {
	const firstAdmin = firstAdminFrom(env);
	return {
		db: d1Kysely(env.DB),
		authDatabase: env.DB,
		...(env.CACHE === undefined ? {} : { cache: kvCache(env.CACHE) }),
		...(env.MEDIA === undefined ? {} : { disk: r2Disk(env.MEDIA, env.MEDIA_URL ?? "/media") }),
		...(env.BETTER_AUTH_SECRET === undefined ? {} : { secret: env.BETTER_AUTH_SECRET }),
		...(env.BETTER_AUTH_URL === undefined ? {} : { baseURL: env.BETTER_AUTH_URL }),
		...(firstAdmin === undefined ? {} : { firstAdmin }),
	};
}

/**
 * `export default cloudflare(site, { render })`: the Workers module. Bindings
 * by name (CloudflareEnv); the first request (or cron tick) of an isolate
 * runs migrations, the seed and the first admin; `scheduled()` runs the
 * CMS's cron (`cms.tasks.scheduled()`). Page loads hand their background
 * writes to `ctx.waitUntil`.
 */
export function cloudflare<const D extends SiteDef, E extends CloudflareEnv = CloudflareEnv>(site: Site<D>, options: SiteOptions<D> = {}) {
	return workerEntry((env: E) => {
		const app = siteApp(site, runtimeFromEnv(env), options);
		return {
			boot: app.boot,
			fetch: (request, ctx) => app.fetch(request, (task) => ctx.waitUntil(task)),
			scheduled: () => app.cms.tasks.scheduled(),
		};
	});
}
