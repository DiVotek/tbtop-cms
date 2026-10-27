import type { BetterAuthOptions } from "better-auth";
import {
	type CompiledQuery,
	type DatabaseConnection,
	type DatabaseIntrospector,
	type Dialect as KyselyDialect,
	Kysely,
	type QueryResult,
	SqliteAdapter,
	SqliteQueryCompiler,
	type TableMetadata,
} from "kysely";
import { defaultOgLocale, localeName } from "../content/localeCatalog";
import { cmsMigrations, type MigrationOptions } from "./migrations";

/** One file for `wrangler d1 migrations apply`: `name` without its number or `.sql`. */
export type D1Migration = { name: string; sql: string };

export type D1MigrationOptions = Pick<MigrationOptions, "userKey"> & {
	/**
	 * Better Auth's options (plugins, model names): its tables go in a file of
	 * their own. Omit it, or pass false, when the host keeps no users here.
	 */
	auth?: BetterAuthOptions | false;
	/** The default language the baseline seeds when no live one exists (default `en`; false skips it). */
	defaultLanguage?: string | false;
	/** The SQL of the files already in the migrations folder: Better Auth's file then holds only what they lack. */
	applied?: string[];
};

/**
 * Where `cloudflare(site, options)` keeps its Better Auth options (false: no
 * Better Auth) on the module it returns, so `d1:migrations --entry` writes
 * the tables that Worker uses. A symbol: Workers reads only the handler names.
 */
export const D1_AUTH = Symbol.for("tbtop.cms.d1Auth");

/** The Better Auth file's name; a later change to its options writes `better_auth_2`, `_3`, … */
export const BETTER_AUTH_MIGRATION = "better_auth";

/**
 * A Kysely that runs nothing: it records each statement as SQLite, answers
 * every query with no rows, and introspects `tables`. Better Auth's planner
 * diffs against those tables; Kysely's DDL builders compile to the SQL D1 runs.
 */
function recorder(tables: TableMetadata[]): { db: Kysely<unknown>; statements: CompiledQuery[] } {
	const statements: CompiledQuery[] = [];
	const connection: DatabaseConnection = {
		executeQuery: async <R>(query: CompiledQuery): Promise<QueryResult<R>> => {
			statements.push(query);
			return { rows: [] };
		},
		streamQuery: () => {
			throw new Error("Not supported while generating SQL.");
		},
	};
	const introspector: DatabaseIntrospector = {
		getSchemas: async () => [],
		getTables: async () => tables,
		getMetadata: async () => ({ tables }),
	};
	const dialect: KyselyDialect = {
		createAdapter: () => new SqliteAdapter(),
		createDriver: () => ({
			init: async () => {},
			acquireConnection: async () => connection,
			beginTransaction: async () => {},
			commitTransaction: async () => {},
			rollbackTransaction: async () => {},
			releaseConnection: async () => {},
			destroy: async () => {},
		}),
		createIntrospector: () => introspector,
		createQueryCompiler: () => new SqliteQueryCompiler(),
	};
	return { db: new Kysely<unknown>({ dialect }), statements };
}

const literal = (value: string) => `'${value.replaceAll("'", "''")}'`;

/** A statement for a SQL file: refuses bound values, which only a running driver could fill in. */
function statement(query: CompiledQuery): string {
	if (query.parameters.length > 0) {
		throw new Error(`Cannot write a migration statement with bound values: ${query.sql}`);
	}
	return `${query.sql};`;
}

/** `ensureDefaultLanguage` as one guarded INSERT: a no-op once the site has a live language. */
function defaultLanguageInsert(locale: string): string {
	const name = localeName(locale);
	if (name === null) {
		throw new Error(`Unknown locale '${locale}': the default language must be one the catalog lists.`);
	}
	const og = defaultOgLocale(locale);
	const columns = ["name", "locale", "og_locale", "is_default", "is_admin_active", "is_frontend_active", "sort_order", "created_at", "updated_at"];
	const values = [literal(name), literal(locale), og === null ? "null" : literal(og), "1", "1", "1", "0", "datetime('now')", "datetime('now')"];
	return `insert into "tbtop_languages" (${columns.map((column) => `"${column}"`).join(", ")}) select ${values.join(", ")} where not exists (select 1 from "tbtop_languages" where "deleted_at" is null);`;
}

/**
 * Kysely's own bookkeeping for the same step, so `migrateCms` (or
 * `tbtop-cms migrate` on a copy) later finds it applied instead of creating
 * the tables again.
 */
function migratorBookkeeping(name: string): string[] {
	return [
		`create table if not exists "tbtop_cms_migrations" ("name" varchar(255) not null primary key, "timestamp" varchar(255) not null);`,
		`insert or ignore into "tbtop_cms_migrations" ("name", "timestamp") values (${literal(name)}, strftime('%Y-%m-%dT%H:%M:%fZ', 'now'));`,
	];
}

async function cmsFiles(options: D1MigrationOptions): Promise<D1Migration[]> {
	const userKey = options.userKey ?? (options.auth ? "string" : "int");
	const migrations = await cmsMigrations({ dialect: "sqlite", userKey }).getMigrations();
	const files: D1Migration[] = [];
	for (const [index, [name, migration]] of Object.entries(migrations).entries()) {
		const { db, statements } = recorder([]);
		await migration.up(db);
		const lines = [...statements.map(statement), ...migratorBookkeeping(name)];
		if (index === 0 && options.defaultLanguage !== false) {
			lines.push(defaultLanguageInsert(options.defaultLanguage ?? "en"));
		}
		files.push({ name, sql: `${lines.join("\n\n")}\n` });
	}
	return files;
}

/** Splits on `separator` outside parentheses and quotes. */
function splitTopLevel(text: string, separator: string): string[] {
	const parts: string[] = [];
	let depth = 0;
	let quote: string | null = null;
	let start = 0;
	for (let i = 0; i < text.length; i++) {
		const char = text[i] as string;
		if (quote !== null) {
			quote = char === quote ? null : quote;
		} else if (char === "'" || char === '"' || char === "`") {
			quote = char;
		} else if (char === "(") {
			depth++;
		} else if (char === ")") {
			depth--;
		} else if (depth === 0 && text.startsWith(separator, i)) {
			parts.push(text.slice(start, i));
			start = i + separator.length;
		}
	}
	parts.push(text.slice(start));
	return parts.map((part) => part.trim()).filter((part) => part !== "");
}

const unquote = (name: string) => name.replace(/^["`[]|["`\]]$/g, "");

function column(definition: string): TableMetadata["columns"][number] | null {
	const match = /^(["`[]?[\w]+["`\]]?)\s+([\w()]+)(.*)$/s.exec(definition);
	if (match === null || /^(primary|unique|foreign|constraint|check)\b/i.test(definition)) {
		return null;
	}
	const rest = (match[3] ?? "").toLowerCase();
	return {
		name: unquote(match[1] as string),
		dataType: (match[2] as string).toLowerCase(),
		isNullable: !rest.includes("not null") && !rest.includes("primary key"),
		isAutoIncrementing: rest.includes("autoincrement"),
		hasDefaultValue: rest.includes("default"),
	};
}

/**
 * The tables and indexes the applied files leave behind, read from their
 * `create table`, `alter table … add column` and `create index` statements:
 * what Better Auth's planner diffs against, as no live D1 is reachable here.
 */
export function schemaOf(applied: string[]): { tables: TableMetadata[]; indexes: Set<string> } {
	const tables = new Map<string, TableMetadata>();
	const indexes = new Set<string>();
	for (const raw of applied.flatMap((sql) => splitTopLevel(sql, ";"))) {
		const sql = raw.replace(/^\s*--.*$/gm, "").trim();
		const create = /^create\s+table\s+(?:if\s+not\s+exists\s+)?(\S+)\s*\((.*)\)$/is.exec(sql);
		if (create !== null) {
			const name = unquote(create[1] as string);
			const columns = splitTopLevel(create[2] as string, ",").flatMap((definition) => column(definition) ?? []);
			tables.set(name, tables.get(name) ?? { name, isView: false, columns });
			continue;
		}
		const add = /^alter\s+table\s+(\S+)\s+add\s+(?:column\s+)?(.*)$/is.exec(sql);
		if (add !== null) {
			const found = column((add[2] as string).trim());
			const table = tables.get(unquote(add[1] as string));
			if (found !== null && table !== undefined) {
				table.columns.push(found);
			}
			continue;
		}
		const index = /^create\s+(?:unique\s+)?index\s+(?:if\s+not\s+exists\s+)?(\S+)/i.exec(sql);
		if (index !== null) {
			indexes.add(unquote(index[1] as string));
		}
	}
	return { tables: [...tables.values()], indexes };
}

/** Better Auth's tables, or what its options added since the applied files; null when nothing is missing. */
async function betterAuthSql(auth: BetterAuthOptions, applied: string[]): Promise<string | null> {
	const { getMigrations } = await import("better-auth/db/migration");
	const { tables, indexes } = schemaOf(applied);
	const { db, statements } = recorder(tables);
	const plan = await getMigrations({ ...auth, database: { db, type: "sqlite" } });
	statements.length = 0;
	const sql = splitTopLevel(await plan.compileMigrations(), ";")
		.filter((line) => {
			const index = /^create\s+(?:unique\s+)?index\s+(\S+)/i.exec(line);
			return index === null || !indexes.has(unquote(index[1] as string));
		})
		.map((line) => `${line};`);
	return sql.length === 0 ? null : `${sql.join("\n\n")}\n`;
}

/**
 * The SQL files for `wrangler d1 migrations apply`: one per CMS migration,
 * then Better Auth's tables. D1 has no transaction for a boot-time migrator
 * and a first request may land in several isolates, so a Worker migrates on
 * deploy instead. Writes nothing: `tbtop-cms d1:migrations` puts them in the
 * folder.
 */
export async function d1Migrations(options: D1MigrationOptions = {}): Promise<D1Migration[]> {
	const files = await cmsFiles(options);
	if (options.auth) {
		const sql = await betterAuthSql(options.auth, options.applied ?? []);
		if (sql !== null) {
			files.push({ name: BETTER_AUTH_MIGRATION, sql });
		}
	}
	return files;
}
