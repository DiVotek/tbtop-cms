import { type DatabaseIntrospector, type DatabaseMetadata, type Kysely, type SchemaMetadata, sql, type TableMetadata } from "kysely";

/**
 * A SQLite introspector that reads `sqlite_master`, then each table's columns
 * with a plain `PRAGMA table_info`. Kysely's own joins the `pragma_table_info`
 * table-valued function, which Cloudflare D1 refuses (SQLITE_AUTH), so the
 * migrator's "does the migrations table exist" check failed there.
 */
export class SqliteMasterIntrospector implements DatabaseIntrospector {
	constructor(private readonly db: Kysely<unknown>) {}

	async getSchemas(): Promise<SchemaMetadata[]> {
		return [];
	}

	async getTables(options: { withInternalKyselyTables?: boolean } = {}): Promise<TableMetadata[]> {
		const { rows } = await sql<{ name: string; type: string }>`select name, type from sqlite_master where type in ('table', 'view') order by name`.execute(this.db);
		const tables: TableMetadata[] = [];
		for (const row of rows) {
			// SQLite's own tables and D1's (_cf_KV) are not the host's.
			if (row.name.startsWith("sqlite_") || row.name.startsWith("_cf_") || (!options.withInternalKyselyTables && row.name.startsWith("kysely_"))) {
				continue;
			}
			tables.push({ name: row.name, isView: row.type === "view", columns: await this.columns(row.name) });
		}
		return tables;
	}

	async getMetadata(options?: { withInternalKyselyTables?: boolean }): Promise<DatabaseMetadata> {
		return { tables: await this.getTables(options) };
	}

	private async columns(table: string): Promise<TableMetadata["columns"]> {
		const { rows } = await sql<{ name: string; type: string; notnull: number; dflt_value: unknown; pk: number }>`pragma table_info(${sql.id(table)})`.execute(this.db);
		return rows.map((column) => ({
			name: column.name,
			dataType: column.type,
			isNullable: !column.notnull,
			isAutoIncrementing: column.pk > 0 && column.type.toLowerCase() === "integer",
			hasDefaultValue: column.dflt_value !== null,
		}));
	}
}

/** The same Kysely, with an introspector D1 accepts; the host's instance is left as it is. */
export function withSqliteMasterIntrospection<DB>(db: Kysely<DB>): Kysely<DB> {
	const copy = db.withoutPlugins();
	Object.defineProperty(copy, "introspection", { value: new SqliteMasterIntrospector(copy as Kysely<unknown>) });
	return copy;
}
