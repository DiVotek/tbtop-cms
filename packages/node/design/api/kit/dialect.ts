import type { Kysely } from "kysely";
import type { Dialect } from "../../../src";

/**
 * The dialect of a Kysely instance, read from its adapter: the host no longer
 * says `dialect: "sqlite"` twice (migrateCms and Better Auth). Read from the
 * adapter's capabilities, not instanceof (a dialect package such as kysely-d1
 * may bring its own Kysely copy) and not its class name (a minified Worker
 * bundle renames it).
 */
export function dialectOf(db: Kysely<unknown>): Dialect {
	const adapter = db.getExecutor().adapter;
	if (adapter.supportsReturning) {
		return adapter.supportsTransactionalDdl ? "postgres" : "sqlite";
	}
	if (!adapter.supportsTransactionalDdl && !adapter.supportsOutput) {
		return "mysql";
	}
	throw new TypeError("tbtop/cms: unknown Kysely dialect; pass `dialect` explicitly.");
}
