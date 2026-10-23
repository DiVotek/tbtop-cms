import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver } from "@tbtop/admin/kysely";
import { betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import type { Kysely } from "kysely";
import { type CmsAuth, cmsAccess, cmsAuth, cmsRoles } from "../src/betterAuth";
import { makeCms, testDialect } from "./support";

const PASSWORD = "correct-horse";

/** A CMS whose admin signs in through Better Auth on the CMS's own database, with an admin and an editor. */
async function authPanel() {
	let auth: CmsAuth | null = null;
	const { cms, db } = await makeCms(null, { authorize: (user, ability) => auth?.authorize(user, ability) ?? false });
	const options = {
		database: { db: db as Kysely<unknown>, type: testDialect },
		secret: "test-secret-test-secret-test-secret",
		baseURL: "http://x",
		emailAndPassword: { enabled: true },
		user: { modelName: "tbtop_users" },
		session: { modelName: "tbtop_sessions" },
		account: { modelName: "tbtop_accounts" },
		verification: { modelName: "tbtop_verifications" },
		plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles })],
	};
	await (await getMigrations(options)).runMigrations();
	const better = betterAuth(options);
	auth = cmsAuth(better);
	const ids: Record<string, string> = {};
	for (const [name, role] of [
		["owner", "admin"],
		["editor", "editor"],
		["member", "user"],
	] as const) {
		const made = await better.api.createUser({ body: { email: `${name}@site.test`, password: PASSWORD, name, role } });
		ids[name] = made.user.id;
	}
	const panel = createAdmin({
		prefix: "/admin",
		pages: [...cms.admin.pages, ...auth.pages(cms)],
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		session: auth.session,
		loginPath: auth.loginPath(cms),
		context: cms.admin.context,
	});
	const jar = new Map<string, string>();
	const visit = async (url: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		if (init.inertia !== false) {
			headers.set("x-inertia", "true");
		}
		headers.set("cookie", [...jar].map(([key, value]) => `${key}=${value}`).join("; "));
		const res = await panel.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		for (const line of res.headers.getSetCookie()) {
			const [pair = ""] = line.split(";");
			const at = pair.indexOf("=");
			const value = pair.slice(at + 1);
			if (value === "" || /max-age=0/i.test(line)) {
				jar.delete(pair.slice(0, at));
			} else {
				jar.set(pair.slice(0, at), value);
			}
		}
		return res;
	};
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const signIn = async (who: string, password = PASSWORD) => post("/admin/login/forms/login", { email: `${who}@site.test`, password }, "/admin/login");
	const rows = async () => {
		const res = await visit("/admin/users/tables/users", { inertia: false });
		return ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
	};
	return { cms, better, ids, jar, visit, post, page, signIn, rows };
}

describe("Better Auth adapter", () => {
	test("a guest is sent to the login page; the right password signs in with Better Auth's cookie", async () => {
		const { visit, signIn, page, jar } = await authPanel();
		const guest = await visit("/admin/pages");
		expect(guest.status).toBe(303);
		expect(guest.headers.get("location")).toBe("/admin/login");
		expect((await visit("/admin/login")).status).toBe(200);

		const wrong = await signIn("owner", "nope-nope-nope");
		expect(wrong.status).toBe(303);
		expect([...jar.keys()].some((key) => key.includes("session_token"))).toBe(false);

		const ok = await signIn("owner");
		expect(ok.headers.get("location")).toBe("/admin/dashboard");
		expect([...jar.keys()].some((key) => key.includes("session_token"))).toBe(true);
		const dashboard = await page("/admin/dashboard");
		expect(dashboard.component).toBeDefined();
		expect(JSON.stringify(dashboard.props)).toContain("owner@site.test");
	});

	test("a role without any CMS section stays a guest, and its session is closed", async () => {
		const { signIn, visit, jar } = await authPanel();
		await signIn("member");
		expect([...jar.keys()].some((key) => key.includes("session_token"))).toBe(false);
		expect((await visit("/admin/pages")).headers.get("location")).toBe("/admin/login");
	});

	test("roles map to sections: the editor edits pages but not settings or users", async () => {
		const { signIn, visit, page } = await authPanel();
		await signIn("editor");
		expect((await visit("/admin/pages")).status).toBe(200);
		expect((await visit("/admin/settings/site")).status).toBe(403);
		expect((await visit("/admin/users")).status).toBe(403);
		const props = JSON.stringify((await page("/admin/pages")).props);
		expect(props).toContain('"/admin/pages"');
		expect(props).not.toContain('"/admin/users"');
	});

	test("logout signs out in Better Auth", async () => {
		const { better, signIn, post, visit, jar } = await authPanel();
		await signIn("owner");
		const cookie = [...jar].map(([key, value]) => `${key}=${value}`).join("; ");
		expect(await better.api.getSession({ headers: new Headers({ cookie }) })).not.toBeNull();
		const out = await post("/admin/logout", {}, "/admin/dashboard");
		expect(out.headers.get("location")).toBe("/admin/login");
		expect(await better.api.getSession({ headers: new Headers({ cookie }) })).toBeNull();
		expect((await visit("/admin/pages")).headers.get("location")).toBe("/admin/login");
	});

	test("the Users page lists, adds, edits and bans through Better Auth's admin API", async () => {
		const { better, ids, signIn, post, rows, visit, page } = await authPanel();
		await signIn("owner");
		expect(JSON.stringify((await page("/admin/pages")).props)).toContain('"/admin/users"');
		expect((await rows()).map((row) => `${row.email} ${row.role}`)).toEqual(["owner@site.test admin", "editor@site.test editor", "member@site.test user"]);

		expect((await post("/admin/users/new/forms/user", { name: "New", email: "new@site.test", role: "editor", password: "long-enough" }, "/admin/users/new")).headers.get("location")).toBe("/admin/users");
		const added = (await rows()).find((row) => row.email === "new@site.test");
		expect(added?.role).toBe("editor");
		const signedIn = await better.api.signInEmail({ body: { email: "new@site.test", password: "long-enough" } });
		expect(signedIn.user.name).toBe("New");

		const edit = `/admin/users/${ids.editor}/edit`;
		expect((await visit(edit)).status).toBe(200);
		await post(`${edit}/forms/user`, { name: "Editor two", role: "admin", password: "brand-new-pass" }, edit);
		const editor = (await rows()).find((row) => row.id === ids.editor);
		expect(editor).toMatchObject({ name: "Editor two", role: "admin" });
		expect((await better.api.signInEmail({ body: { email: "editor@site.test", password: "brand-new-pass" } })).user.id).toBe(ids.editor as string);

		await post("/admin/users/actions/ban", { payload: { row: { id: ids.member } } }, "/admin/users");
		expect(Boolean((await rows()).find((row) => row.id === ids.member)?.banned)).toBe(true);
		await expect(better.api.signInEmail({ body: { email: "member@site.test", password: PASSWORD } })).rejects.toThrow();
	});

	test("an admin cannot change their own role or ban themselves", async () => {
		const { ids, signIn, post, rows } = await authPanel();
		await signIn("owner");
		const edit = `/admin/users/${ids.owner}/edit`;
		await post(`${edit}/forms/user`, { name: "owner", role: "editor" }, edit);
		expect((await rows()).find((row) => row.id === ids.owner)?.role).toBe("admin");

		const res = await post("/admin/users/actions/ban", { payload: { row: { id: ids.owner } } }, "/admin/users");
		expect(JSON.stringify(await res.json())).toContain("You cannot ban yourself");
		expect(Boolean((await rows()).find((row) => row.id === ids.owner)?.banned)).toBe(false);
	});
});
