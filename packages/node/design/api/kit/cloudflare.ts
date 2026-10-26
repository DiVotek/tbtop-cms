import { r2Disk } from "@tbtop/admin";
import { kvCache } from "../../../src";
import type { Env } from "../shared/workers";
import type { Runtime } from "../v2-config/package";
import { d1Kysely } from "./d1";

/**
 * Q16's core: what the CMS runs on, from the Worker's bindings by name
 * (DB: D1, CACHE: KV, MEDIA: R2, BETTER_AUTH_SECRET/URL). Better Auth gets
 * the D1 binding itself: its own D1 dialect introspects without the
 * pragma join D1 refuses.
 */
export function runtimeFromEnv(env: Env, options: { mediaUrl?: string } = {}): Runtime {
	return {
		db: d1Kysely(env.DB),
		authDatabase: env.DB,
		cache: kvCache(env.CACHE),
		disk: r2Disk(env.MEDIA, options.mediaUrl ?? "/media"),
		secret: env.BETTER_AUTH_SECRET,
		...(env.BETTER_AUTH_URL === undefined ? {} : { baseURL: env.BETTER_AUTH_URL }),
	};
}
