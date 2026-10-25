import { Database } from "bun:sqlite";
import { describe, expect, test } from "bun:test";
import { type DatabaseConnection, type Driver, Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, implementBlocks, migrateCms, seedFixtures } from "../src";

/**
 * SQLite as Cloudflare D1 answers it through kysely-d1: no transactions, and
 * the pragma_* table-valued functions refused (SQLITE_AUTH).
 */
class D1LikeDialect extends BunSqliteDialect {
	override createDriver(): Driver {
		const driver = super.createDriver();
		driver.beginTransaction = async () => {
			throw new Error("Transactions are not supported yet.");
		};
		const acquire = driver.acquireConnection.bind(driver);
		driver.acquireConnection = async (): Promise<DatabaseConnection> => {
			const connection = await acquire();
			const execute = connection.executeQuery.bind(connection);
			connection.executeQuery = (query) => {
				if (/pragma_\w+\s*\(/i.test(query.sql)) {
					throw new Error("not authorized: SQLITE_AUTH");
				}
				return execute(query);
			};
			return connection;
		};
		return driver;
	}
}

const d1 = () => new Kysely<CmsDatabase>({ dialect: new D1LikeDialect({ database: new Database(":memory:") }) });

describe("Cloudflare D1", () => {
	test("migrateCms runs, twice, without pragma_table_info", async () => {
		const db = d1();
		await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
		await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
		expect(await createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls) }).site().languages.defaultLocale()).toBe("en");
	});

	test("a fixture whose default language is not en seeds without a transaction", async () => {
		const db = d1();
		await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
		const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls) });
		const languages = demoFixture.languages.map((language) => ({ ...language, default: language.locale === "uk" }));
		await seedFixtures(cms, { ...demoFixture, languages });
		const rows = await db.selectFrom("tbtop_languages").select(["locale", "is_default", "is_frontend_active"]).orderBy("locale").execute();
		expect(rows.map((row) => [row.locale, Number(row.is_default), Number(row.is_frontend_active)])).toEqual([
			["en", 0, 1],
			["uk", 1, 1],
		]);
	});

	test("a shared block deletes without a transaction", async () => {
		const db = d1();
		await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
		const blocks = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls) }).site().sharedBlocks;
		const id = await blocks.create({ name: "Promo", kind: "Hero", data: {} });
		await blocks.delete(id);
		expect(await blocks.all()).toEqual([]);
	});
});
