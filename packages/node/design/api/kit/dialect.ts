import type { Kysely } from "kysely";
import type { Dialect } from "../../../src";

/**
 * The dialect of a Kysely instance, read from its adapter: the host no longer
 * says `dialect: "sqlite"` twice (migrateCms and Better Auth). Matched by the
 * adapter's class chain name, not instanceof: a dialect package (kysely-d1,
 * kysely-bun-sqlite) may bring its own Kysely copy.
 */
export function dialectOf(db: Kysely<unknown>): Dialect {
	for (let proto = Object.getPrototypeOf(db.getExecutor().adapter); proto !== null; proto = Object.getPrototypeOf(proto)) {
		const name = String(proto.constructor?.name ?? "").toLowerCase();
		for (const dialect of ["sqlite", "postgres", "mysql"] as const) {
			if (name.includes(dialect)) {
				return dialect;
			}
		}
	}
	throw new TypeError("tbtop/cms: unknown Kysely dialect; pass `dialect` explicitly.");
}
