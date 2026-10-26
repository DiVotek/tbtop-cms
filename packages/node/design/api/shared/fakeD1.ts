import { Database } from "bun:sqlite";
import type { D1Database, D1PreparedStatement, D1Result } from "./workers";

/**
 * A D1 binding over bun:sqlite, as Workers answers: `prepare/bind/all/run`,
 * `batch`, `exec`, no transactions through it, and Kysely's introspection
 * join over pragma_table_info refused (SQLITE_AUTH), as D1 refuses it. It
 * lets the Worker entries run here; workerd itself is not involved.
 */
export function fakeD1(): D1Database {
	const sqlite = new Database(":memory:");
	const exec = (sql: string, params: unknown[]): D1Result => {
		if (/pragma_\w+\s*\(/i.test(sql) && /\bjoin\b/i.test(sql)) {
			throw new Error("not authorized: SQLITE_AUTH");
		}
		if (/^\s*(begin|commit|rollback)\b/i.test(sql)) {
			throw new Error("D1: use batch(), not transactions");
		}
		// Stricter than D1: a boolean or undefined that reaches the binding fails the proof.
		if (params.some((value) => typeof value === "boolean" || value === undefined)) {
			throw new Error("fakeD1: boolean/undefined binding; convert before D1");
		}
		const bound = params as never[];
		const query = sqlite.query(sql);
		if (query.columnNames.length > 0) {
			const results = query.all(...bound) as Record<string, unknown>[];
			const changes = sqlite.query("select changes() as c, last_insert_rowid() as id").get() as { c: number; id: number };
			return { results, success: true, meta: /^\s*select\b/i.test(sql) ? { changes: 0, last_row_id: 0, duration: 0 } : { changes: changes.c, last_row_id: changes.id, duration: 0 } };
		}
		const run = query.run(...bound);
		return { results: [], success: true, meta: { changes: run.changes, last_row_id: Number(run.lastInsertRowid), duration: 0 } };
	};
	const bodies = new WeakMap<D1PreparedStatement, [string, unknown[]]>();
	const statement = (sql: string, params: unknown[] = []): D1PreparedStatement => {
		const prepared: D1PreparedStatement = {
		bind: (...values) => statement(sql, values),
		all: async <T>() => exec(sql, params) as D1Result<T>,
		run: async <T>() => exec(sql, params) as D1Result<T>,
		first: async <T>(column?: string) => {
			const row = exec(sql, params).results[0] ?? null;
			return (column === undefined || row === null ? row : row[column]) as T | null;
		},
		raw: async <T>() => exec(sql, params).results.map((row) => Object.values(row)) as T[],
		};
		bodies.set(prepared, [sql, params]);
		return prepared;
	};
	return {
		prepare: (sql) => statement(sql),
		// D1 runs a batch as one transaction: all statements or none.
		batch: async <T>(statements: D1PreparedStatement[]) =>
			sqlite.transaction(() => statements.map((s) => exec(...(bodies.get(s) as [string, unknown[]])) as D1Result<T>))(),
		exec: async (sql) => sqlite.exec(sql),
	};
}
