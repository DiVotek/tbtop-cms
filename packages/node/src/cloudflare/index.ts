import { r2Disk } from "@tbtop/admin";
import type { BetterAuthOptions } from "better-auth";
import { defaultAuthSchema } from "../betterAuth/defaultAuth";
import { D1_AUTH } from "../db/d1Migrations";
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

/** The boot's error when D1 has no tables yet, with the deploy step that makes them. */
function unmigrated(error: unknown): unknown {
	if (!(error instanceof Error) || !/no such table/i.test(error.message)) {
		return error;
	}
	return new Error(
		`tbtop/cms: the D1 database has no CMS tables (${error.message}). Migrate on deploy: \`tbtop-cms d1:migrations --entry <this worker>\` writes the SQL files, \`wrangler d1 migrations apply DB --remote\` applies them.`,
		{ cause: error },
	);
}

/**
 * `export default cloudflare(site, { render })`: the Workers module. Bindings
 * by name (CloudflareEnv). It does not migrate: D1 has no transactions and an
 * isolate's first request may come to several at once, so the tables come on
 * deploy from `tbtop-cms d1:migrations` (SQL files of the CMS and Better
 * Auth) and `wrangler d1 migrations apply`. The first request (or cron tick)
 * of an isolate seeds an empty site and creates the first admin;
 * `scheduled()` runs the CMS's cron (`cms.tasks.scheduled()`). Page loads
 * hand their background writes to `ctx.waitUntil`. The module also carries
 * Better Auth's options under a symbol, for `d1:migrations --entry`.
 */
export function cloudflare<const D extends SiteDef, E extends CloudflareEnv = CloudflareEnv>(site: Site<D>, options: SiteOptions<D> = {}) {
	const entry = workerEntry((env: E) => {
		const app = siteApp(site, runtimeFromEnv(env), options);
		return {
			boot: () =>
				app.setup().catch((error: unknown) => {
					throw unmigrated(error);
				}),
			fetch: (request, ctx) => app.fetch(request, (task) => ctx.waitUntil(task)),
			scheduled: () => app.cms.tasks.scheduled(),
		};
	});
	const auth: BetterAuthOptions | false = options.auth === false || options.config?.auth !== undefined ? false : defaultAuthSchema(options.auth);
	return Object.assign(entry, { [D1_AUTH]: auth } as { [D1_AUTH]: BetterAuthOptions | false });
}
