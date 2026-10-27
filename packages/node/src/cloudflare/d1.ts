import {
	type CompiledQuery,
	type DatabaseConnection,
	type DatabaseIntrospector,
	type Dialect,
	type Driver,
	Kysely,
	type QueryResult,
	SqliteAdapter,
	SqliteQueryCompiler,
} from "kysely";
import { SqliteMasterIntrospector } from "../db/sqliteIntrospector";
import type { D1Database } from "./types";

/**
 * Kysely over a D1 binding (what `kysely-d1` does), so a Worker host never
 * picks a dialect package. No transactions (D1 has none); the CMS migrator
 * reads tables from sqlite_master, which D1 allows.
 */
class D1Connection implements DatabaseConnection {
	constructor(private readonly d1: D1Database) {}
	async executeQuery<R>(query: CompiledQuery): Promise<QueryResult<R>> {
		const result = await this.d1.prepare(query.sql).bind(...query.parameters.map(toD1)).all();
		const { changes, last_row_id } = result.meta;
		return {
			rows: result.results as R[],
			numAffectedRows: BigInt(changes),
			insertId: BigInt(last_row_id),
		};
	}
	// biome-ignore lint/correctness/useYield: D1 has no cursors.
	async *streamQuery(): AsyncIterableIterator<QueryResult<never>> {
		throw new Error("D1 does not stream.");
	}
}

class D1Driver implements Driver {
	constructor(private readonly d1: D1Database) {}
	async init() {}
	async acquireConnection() {
		return new D1Connection(this.d1);
	}
	async beginTransaction(): Promise<void> {
		throw new Error("D1 has no transactions.");
	}
	async commitTransaction() {}
	async rollbackTransaction() {}
	async releaseConnection() {}
	async destroy() {}
}

export class D1Dialect implements Dialect {
	constructor(private readonly d1: D1Database) {}
	createAdapter() {
		return new SqliteAdapter();
	}
	createDriver() {
		return new D1Driver(this.d1);
	}
	createQueryCompiler() {
		return new SqliteQueryCompiler();
	}
	createIntrospector(db: Kysely<unknown>): DatabaseIntrospector {
		// Kysely's own joins pragma_table_info, which D1 refuses.
		return new SqliteMasterIntrospector(db);
	}
}

export const d1Kysely = <DB = unknown>(binding: D1Database) => new Kysely<DB>({ dialect: new D1Dialect(binding) });

/** Booleans as 0/1 here, not left to D1's own coercion; `undefined` is a bug D1 would refuse. */
function toD1(value: unknown): unknown {
	if (value === undefined) {
		throw new TypeError("D1: an undefined binding");
	}
	return typeof value === "boolean" ? Number(value) : value;
}
