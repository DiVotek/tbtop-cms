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
