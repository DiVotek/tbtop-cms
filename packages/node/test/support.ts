import { Database } from "bun:sqlite";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsConfig, type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, type Fixture, implementBlocks, migrateCms, seedFixtures } from "../src";

/** A CMS over a fresh in-memory SQLite database, migrated and seeded with `fixture`. */
export async function makeCms(fixture: Fixture | null = demoFixture, config: Partial<CmsConfig> = {}) {
	const db = new Kysely<CmsDatabase>({ dialect: new BunSqliteDialect({ database: new Database(":memory:") }) });
	await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
	const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", ...config });
	const ids = fixture === null ? {} : await seedFixtures(cms, fixture);
	return { cms, db, ids };
}

export const get = (path: string, init?: RequestInit) => new Request(`https://site.test${path}`, init);
