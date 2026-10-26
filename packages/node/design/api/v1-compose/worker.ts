import { r2Disk } from "@tbtop/admin";
import { kvCache } from "../../../src";
import { d1Kysely } from "../kit/d1";
import { workerEntry } from "../kit/worker";
import type { Env } from "../shared/workers";
import { buildSite } from "./site";

// V1 · Q16 as building blocks: the package ships d1Kysely, kvCache, r2Disk and
// workerEntry; the host binds them itself. (Auth goes through the D1 binding
// itself, as Better Auth's own introspection needs.)
export default workerEntry((env: Env) => {
	const site = buildSite(d1Kysely(env.DB), {
		auth: { database: env.DB, secret: env.BETTER_AUTH_SECRET, ...(env.BETTER_AUTH_URL === undefined ? {} : { baseURL: env.BETTER_AUTH_URL }) },
		cache: kvCache(env.CACHE),
		disk: r2Disk(env.MEDIA, "https://media.example.com"),
	});
	return { boot: site.boot, fetch: (request) => site.fetch(request), scheduled: () => site.cms.tasks.scheduled() };
});
