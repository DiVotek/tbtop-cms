import { Database } from "bun:sqlite";
import { type DatabaseConnection, type Driver, Kysely, MysqlDialect, PostgresDialect } from "kysely";
import { createPool } from "mysql2";
import pg from "pg";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsConfig, type CmsDatabase, type Dialect, cmsBlockImpls, cmsBlocks, createCms, demoFixture, type Fixture, implementBlocks, migrateCms, seedFixtures } from "../src";

/**
 * bun:sqlite binds a JS boolean, better-sqlite3 (Node hosts) throws on one.
 * Failing on it here keeps the package bindable on every SQLite driver.
 */
class StrictBindsDialect extends BunSqliteDialect {
	override createDriver(): Driver {
		const driver = super.createDriver();
		const acquire = driver.acquireConnection.bind(driver);
		driver.acquireConnection = async (): Promise<DatabaseConnection> => {
			const connection = await acquire();
			const execute = connection.executeQuery.bind(connection);
			connection.executeQuery = (query) => {
				if (query.parameters.some((value) => typeof value === "boolean")) {
					throw new TypeError(`A boolean bound in: ${query.sql}`);
				}
				return execute(query);
			};
			return connection;
		};
		return driver;
	}
}

/**
 * `TBTOP_TEST_DB=postgres|mysql` runs the suite on a server (DB_HOST, DB_PORT,
 * DB_USERNAME, DB_PASSWORD); every CMS gets its own fresh database.
 * The default is in-memory SQLite.
 */
export const testDialect = (process.env.TBTOP_TEST_DB ?? "sqlite") as Dialect;

let fresh = 0;
const run = `${process.pid}_${Date.now().toString(36)}`;

async function freshDb(): Promise<Kysely<CmsDatabase>> {
	if (testDialect === "sqlite") {
		return new Kysely<CmsDatabase>({ dialect: new StrictBindsDialect({ database: new Database(":memory:") }) });
	}
	const name = `tbtop_test_${run}_${++fresh}`;
	const host = process.env.DB_HOST ?? "127.0.0.1";
	const user = process.env.DB_USERNAME ?? (testDialect === "postgres" ? "postgres" : "root");
	const password = process.env.DB_PASSWORD ?? (testDialect === "postgres" ? "postgres" : "root");
	if (testDialect === "postgres") {
		const port = Number(process.env.DB_PORT ?? 5432);
		const admin = new pg.Client({ host, port, user, password, database: process.env.DB_DATABASE ?? "postgres" });
		await admin.connect();
		// A database, not a schema: Kysely's Migrator looks its tables up across every schema.
		await admin.query(`CREATE DATABASE ${name}`);
		await admin.end();
		const pool = new pg.Pool({ host, port, user, password, database: name, max: 2, idleTimeoutMillis: 1000 });
		return new Kysely<CmsDatabase>({ dialect: new PostgresDialect({ pool }) });
	}
	const port = Number(process.env.DB_PORT ?? 3306);
	const admin = createPool({ host, port, user, password, connectionLimit: 1 });
	await admin.promise().query(`CREATE DATABASE ${name}`);
	await admin.promise().end();
	const pool = createPool({ host, port, user, password, database: name, connectionLimit: 2, idleTimeout: 1000 });
	return new Kysely<CmsDatabase>({ dialect: new MysqlDialect({ pool }) });
}

/** A CMS over a fresh database (in-memory SQLite unless TBTOP_TEST_DB says otherwise), migrated and seeded with `fixture`. */
export async function makeCms(fixture: Fixture | null = demoFixture, config: Partial<CmsConfig> = {}) {
	const db = await freshDb();
	await migrateCms(db as Kysely<unknown>, { dialect: testDialect });
	const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", ...config });
	const ids = fixture === null ? {} : await seedFixtures(cms, fixture);
	return { cms, db, ids };
}

export const get = (path: string, init?: RequestInit) => new Request(`https://site.test${path}`, init);
