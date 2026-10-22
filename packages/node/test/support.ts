import { Database } from "bun:sqlite";
import { type DatabaseConnection, type Driver, Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsConfig, type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, type Fixture, implementBlocks, migrateCms, seedFixtures } from "../src";

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

/** A CMS over a fresh in-memory SQLite database, migrated and seeded with `fixture`. */
export async function makeCms(fixture: Fixture | null = demoFixture, config: Partial<CmsConfig> = {}) {
	const db = new Kysely<CmsDatabase>({ dialect: new StrictBindsDialect({ database: new Database(":memory:") }) });
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
	const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", ...config });
	const ids = fixture === null ? {} : await seedFixtures(cms, fixture);
	return { cms, db, ids };
}

export const get = (path: string, init?: RequestInit) => new Request(`https://site.test${path}`, init);
