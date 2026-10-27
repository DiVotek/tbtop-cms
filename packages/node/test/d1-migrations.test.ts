import { Database } from "bun:sqlite";
import { describe, expect, test } from "bun:test";
import { mkdtemp, readdir, readFile, rename, rm, writeFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { betterAuth, type BetterAuthOptions } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin, username } from "better-auth/plugins";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, d1Migrations, implementBlocks, migrateCms } from "../src";
import { runD1Migrations, writeD1Migrations } from "../src/cli/d1";
import { cmsAccess, cmsRoles } from "../src/betterAuth";

const auth = (extra: Partial<BetterAuthOptions> = {}): BetterAuthOptions => ({
	emailAndPassword: { enabled: true },
	user: { modelName: "tbtop_users" },
	session: { modelName: "tbtop_sessions" },
	account: { modelName: "tbtop_accounts" },
	verification: { modelName: "tbtop_verifications" },
	plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles }), ...(extra.plugins ?? [])],
});

/** What `wrangler d1 migrations apply` does with the folder: each file, in order, as one script. */
async function apply(sqlite: Database, dir: string, files?: string[]) {
	for (const file of files ?? (await readdir(dir)).sort()) {
		sqlite.exec(await readFile(join(dir, file), "utf8"));
	}
}

const tempDir = () => mkdtemp(join(tmpdir(), "tbtop-d1-"));

describe("D1 migrations as SQL files", () => {
	test("the files give a working site with Better Auth, and migrateCms then finds nothing to do", async () => {
		const dir = await tempDir();
		const written = await writeD1Migrations(dir, { auth: auth() });
		expect(written.map((path) => path.slice(dir.length + 1))).toEqual(["0001_2026_10_22_000000_tbtop_cms_baseline.sql", "0002_2026_10_27_000000_tbtop_page_title_to_seo.sql", "0003_better_auth.sql"]);

		const sqlite = new Database(":memory:");
		await apply(sqlite, dir);
		const db = new Kysely<CmsDatabase>({ dialect: new BunSqliteDialect({ database: sqlite }) });
		await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });
		expect(await db.selectFrom("tbtop_languages").select("locale").execute()).toEqual([{ locale: "en" }]);
		expect(await createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls) }).site().languages.defaultLocale()).toBe("en");

		const options = {
			...auth(),
			plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles })],
			database: { db: db as Kysely<unknown>, type: "sqlite" as const },
			secret: "test-secret-test-secret-test-secret",
			baseURL: "http://x",
		};
		const plan = await getMigrations(options);
		expect([plan.toBeCreated, plan.toBeAdded, plan.toBeAddedIndexes]).toEqual([[], [], []]);
		const made = await betterAuth(options).api.createUser({ body: { email: "anna@blog.test", password: "correct-horse", name: "Anna", role: "editor" } });
		expect(made.user.email).toBe("anna@blog.test");
	});

	test("a directory from before the title move keeps its files; the new migration comes after them", async () => {
		const dir = await tempDir();
		await writeD1Migrations(dir, { auth: auth() });
		// The 0.0 layout: the baseline, then Better Auth as 0002.
		await rm(join(dir, "0002_2026_10_27_000000_tbtop_page_title_to_seo.sql"));
		await rename(join(dir, "0003_better_auth.sql"), join(dir, "0002_better_auth.sql"));
		const added = await writeD1Migrations(dir, { auth: auth() });
		expect(added.map((path) => path.slice(dir.length + 1))).toEqual(["0003_2026_10_27_000000_tbtop_page_title_to_seo.sql"]);
		expect((await readdir(dir)).sort()).toEqual(["0001_2026_10_22_000000_tbtop_cms_baseline.sql", "0002_better_auth.sql", "0003_2026_10_27_000000_tbtop_page_title_to_seo.sql"]);
	});

	test("a second run writes nothing; a Better Auth plugin added later gets its own file with only the new columns", async () => {
		const dir = await tempDir();
		await writeD1Migrations(dir, { auth: auth() });
		expect(await writeD1Migrations(dir, { auth: auth() })).toEqual([]);

		// A hand-written file: the apostrophe in its comment must not hide the column it adds.
		await writeFile(join(dir, "0003_display_name.sql"), '-- don\'t touch\ncreate table "notes" ("body" text);\nalter table "tbtop_users" add column "displayUsername" text;\n');
		const sqlite = new Database(":memory:");
		await apply(sqlite, dir);
		const added = await writeD1Migrations(dir, { auth: auth({ plugins: [username()] }) });
		expect(added.map((path) => path.slice(dir.length + 1))).toEqual(["0004_better_auth_2.sql"]);
		const sql = await readFile(added[0] as string, "utf8");
		expect(sql).toContain('alter table "tbtop_users" add column "username"');
		expect(sql).not.toContain("create table");
		expect(sql).not.toContain('"displayUsername"');
		await apply(sqlite, dir, ["0004_better_auth_2.sql"]);

		const db = new Kysely<unknown>({ dialect: new BunSqliteDialect({ database: sqlite }) });
		const plan = await getMigrations({ ...auth({ plugins: [username()] }), database: { db, type: "sqlite" } });
		expect([plan.toBeCreated, plan.toBeAdded]).toEqual([[], []]);
	});

	test("without auth only the CMS files; the default language is chosen or left out", async () => {
		const [uk] = await d1Migrations({ defaultLanguage: "uk" });
		expect((await d1Migrations()).map((file) => file.name)).toEqual(["2026_10_22_000000_tbtop_cms_baseline", "2026_10_27_000000_tbtop_page_title_to_seo"]);
		expect((await d1Migrations())[1]?.sql).toContain('update "tbtop_pages" set "seo"');
		expect(uk?.sql).toContain("'uk'");
		expect((await d1Migrations({ defaultLanguage: false }))[0]?.sql).not.toContain('insert into "tbtop_languages"');
		await expect(d1Migrations({ defaultLanguage: "xx" })).rejects.toThrow("Unknown locale");
	});

	test("tbtop-cms d1:migrations reads Better Auth's options from the Worker entry", async () => {
		const dir = await tempDir();
		const lines: string[] = [];
		const io = { out: (line: string) => lines.push(line), err: (line: string) => lines.push(`ERR ${line}`) };
		expect(await runD1Migrations(["d1:migrations", "--out", dir, "--entry", "example/site/worker.ts", "--locale", "uk"], io)).toBe(0);
		expect((await readdir(dir)).sort()).toEqual(["0001_2026_10_22_000000_tbtop_cms_baseline.sql", "0002_2026_10_27_000000_tbtop_page_title_to_seo.sql", "0003_better_auth.sql"]);
		expect(await readFile(join(dir, "0003_better_auth.sql"), "utf8")).toContain('create table "tbtop_sessions"');
		expect(lines.at(-1)).toContain("wrangler d1 migrations apply");

		lines.length = 0;
		expect(await runD1Migrations(["d1:migrations", "--out", dir], io)).toBe(0);
		expect(lines).toEqual([`Nothing to add: ${dir} is up to date.`]);
	});
});
