import type { InsertResult, Kysely } from "kysely";

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
