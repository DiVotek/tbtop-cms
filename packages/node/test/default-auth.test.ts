import { Database } from "bun:sqlite";
import { describe, expect, test } from "bun:test";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { defaultAuth } from "../src/betterAuth";

describe("defaultAuth().instance", () => {
	test("createUser runs as a server call, with no request headers and any of the site's roles", async () => {
		const db = new Kysely<{ tbtop_users: { email: string; role: string } }>({ dialect: new BunSqliteDialect({ database: new Database(":memory:") }) });
		const auth = defaultAuth(db as Kysely<unknown>, { secret: "default-auth-test-secret-0123456789" });
		await auth.migrate();
		const { user } = await auth.instance.api.createUser({ body: { email: "owner@site.test", password: "correct-horse-battery", name: "Owner", role: "admin" } });
		expect(user.id).toBeString();
		expect(await db.selectFrom("tbtop_users").select(["email", "role"]).execute()).toEqual([{ email: "owner@site.test", role: "admin" }]);
	});
});

describe("defaultAuth({ firstAdmin })", () => {
	const owner = { email: "owner@site.test", password: "correct-horse-battery" };
	const start = () => {
		const db = new Kysely<{ tbtop_users: { email: string; role: string } }>({ dialect: new BunSqliteDialect({ database: new Database(":memory:") }) });
		const make = (firstAdmin?: typeof owner) => defaultAuth(db as Kysely<unknown>, { secret: "default-auth-test-secret-0123456789", ...(firstAdmin === undefined ? {} : { firstAdmin }) });
		const roles = () => db.selectFrom("tbtop_users").select(["email", "role"]).orderBy("email").execute();
		return { make, roles };
	};

	test("migrate() creates the first admin on a createCms host, and only while the site has none", async () => {
		const { make, roles } = start();
		await make(owner).migrate();
		expect(await roles()).toEqual([{ email: "owner@site.test", role: "admin" }]);
		// Other secrets on the next boot change nothing while an admin exists.
		await make({ email: "other@site.test", password: owner.password }).migrate();
		expect(await roles()).toEqual([{ email: "owner@site.test", role: "admin" }]);
	});

	test("setup() is the same step for tables migrated elsewhere; without the option it does nothing", async () => {
		const { make, roles } = start();
		await make().migrate();
		expect(await roles()).toEqual([]);
		await make(owner).setup();
		expect(await roles()).toEqual([{ email: "owner@site.test", role: "admin" }]);
	});

	test("an email of an existing non-admin fails instead of leaving the site without an admin", async () => {
		const { make } = start();
		const auth = make();
		await auth.migrate();
		await auth.ensureUser(owner.email, "editor", { password: owner.password });
		await expect(make(owner).setup()).rejects.toThrow("not an admin");
	});
});
