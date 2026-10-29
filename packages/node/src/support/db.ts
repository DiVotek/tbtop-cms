import type { InsertResult, Kysely } from "kysely";
import type { Dialect } from "../db/migrations";

type Insert = {
	executeTakeFirstOrThrow(): Promise<InsertResult>;
	returning(selection: "id"): { executeTakeFirstOrThrow(): Promise<{ id: unknown }> };
};

/**
 * The new row's id: RETURNING where the dialect has it (SQLite, Postgres),
 * insertId on MySQL. `qb` is an `insertInto(...).values(...)` builder.
 */
export async function insertId<DB>(db: Kysely<DB>, qb: object): Promise<number> {
	const insert = qb as Insert;
	if (db.getExecutor().adapter.supportsReturning) {
		return Number((await insert.returning("id").executeTakeFirstOrThrow()).id);
	}
	return Number((await insert.executeTakeFirstOrThrow()).insertId);
}

/**
 * The Kysely dialect behind a db: by the adapter's class name up its
 * prototype chain (a dialect package may bring its own copy of kysely, so
 * instanceof would miss it), then, when a minifier renamed the classes, by
 * what the adapter supports (Postgres: transactional DDL; MySQL: no
 * RETURNING; SQLite, D1 included: RETURNING without transactional DDL).
 */
export function dialectOfDb(db: { getExecutor(): { adapter: object } }): Dialect | null {
	const adapter = db.getExecutor().adapter;
	for (let proto = Object.getPrototypeOf(adapter); proto !== null; proto = Object.getPrototypeOf(proto)) {
		const name = String(proto.constructor?.name ?? "");
		for (const dialect of ["postgres", "mysql", "sqlite"] as const) {
			if (name.toLowerCase().includes(dialect)) {
				return dialect;
			}
		}
	}
	const supports = adapter as { supportsReturning?: unknown; supportsTransactionalDdl?: unknown };
	if (typeof supports.supportsReturning !== "boolean" || typeof supports.supportsTransactionalDdl !== "boolean") {
		return null;
	}
	if (supports.supportsTransactionalDdl) {
		return "postgres";
	}
	return supports.supportsReturning ? "sqlite" : "mysql";
}
