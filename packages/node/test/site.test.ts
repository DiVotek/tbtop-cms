import { Database } from "bun:sqlite";
import { mkdtemp, readdir, readFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import { describe, expect, test } from "bun:test";
import { memoryDisk } from "@tbtop/admin";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { app } from "../example/site/node";
import { site } from "../example/site/site";
import worker from "../example/site/worker";
import type { CloudflareEnv, ExecutionContext } from "../src/cloudflare";
import { block, defineSite, f, pageType } from "../src/define";
import { pageTypeDefs } from "../src/define/define";
import { writeD1Migrations } from "../src/cli/d1";
import { D1_SCHEMA } from "../src/db/d1Migrations";
import { fakeD1 } from "./fakeD1";

// The full-API example (example/site) is the proof: it runs here as a Node
// host on SQLite (booted twice on one database) and as a Worker on a D1-like
// binding, KV and R2 (its tables from the SQL files `tbtop-cms d1:migrations`
// writes, as `wrangler d1 migrations apply` runs them), and both answer the
// same checks.

const ADMIN = { email: "owner@site.test", password: "correct-horse-battery" };
process.env.BETTER_AUTH_SECRET ??= "site-test-secret-site-test-secret";
process.env.BETTER_AUTH_URL ??= "http://site.test";
process.env.TBTOP_ADMIN_EMAIL = ADMIN.email;
process.env.TBTOP_ADMIN_PASSWORD = ADMIN.password;

type Host = { fetch(request: Request): Promise<Response> };

const memoryDb = () => new Kysely({ dialect: new BunSqliteDialect({ database: new Database(":memory:") }) });

describe("serve(site, { db })", () => {
	const db = memoryDb();
	checks(async () => {
		// A second process on the same database: the boot is idempotent.
		await app(db).ready();
		return app(db);
	});

	test("the secrets create the first admin only while the site has none", async () => {
		process.env.TBTOP_ADMIN_EMAIL = "second@site.test";
		try {
			await app(db).ready();
		} finally {
			process.env.TBTOP_ADMIN_EMAIL = ADMIN.email;
		}
		const second = await (db as unknown as Kysely<{ tbtop_users: { email: string } }>).selectFrom("tbtop_users").select("email").where("email", "=", "second@site.test").execute();
		expect(second).toEqual([]);
	});

	test("TBTOP_ADMIN_EMAIL of an existing non-admin fails the boot instead of leaving the site without an admin", async () => {
		const fresh = memoryDb();
		const { TBTOP_ADMIN_EMAIL, TBTOP_ADMIN_PASSWORD } = process.env;
		delete process.env.TBTOP_ADMIN_EMAIL;
		delete process.env.TBTOP_ADMIN_PASSWORD;
		try {
			const host = app(fresh);
			await host.ready();
			await host.users.ensure("editor@site.test", "editor", { password: "editor-password-1" });
			process.env.TBTOP_ADMIN_EMAIL = "editor@site.test";
			process.env.TBTOP_ADMIN_PASSWORD = "editor-password-2";
			await expect(app(fresh).ready()).rejects.toThrow(/not an admin/);
		} finally {
			process.env.TBTOP_ADMIN_EMAIL = TBTOP_ADMIN_EMAIL;
			process.env.TBTOP_ADMIN_PASSWORD = TBTOP_ADMIN_PASSWORD;
		}
	});

	test("users.ensure seeds an editor, never an admin", async () => {
		const host = app(db);
		await host.ready();
		await expect(host.users.ensure("intruder@site.test", "admin", { password: "intruder-password-1" })).rejects.toThrow(/admin/);
		await expect(host.users.ensure("typo@site.test", "editr", { password: "typo-password-1" })).rejects.toThrow(/unknown role/);
		await host.users.ensure("editor@site.test", "editor", { password: "editor-password-1" });
		const roles = await (db as unknown as Kysely<{ tbtop_users: { email: string; role: string } }>).selectFrom("tbtop_users").select(["email", "role"]).where("email", "in", ["intruder@site.test", "typo@site.test", "editor@site.test"]).execute();
		expect(roles).toEqual([{ email: "editor@site.test", role: "editor" }]);
	});
});

describe("cloudflare(site)", () => {
	const kv = new Map<string, string>();
	const media = memoryDisk("https://media.test");
	const env: CloudflareEnv = {
		DB: fakeD1(),
		CACHE: { get: async (key) => kv.get(key) ?? null, put: async (key, value) => void kv.set(key, value) },
		MEDIA: {
			put: async (key, value) => void (await media.put(key, value, { contentType: "application/octet-stream" })),
			get: async (key) => {
				const blob = await media.get(key);
				return blob === null ? null : { blob: async () => blob };
			},
			delete: (key) => media.delete(key),
		},
		BETTER_AUTH_SECRET: "worker-secret-worker-secret-worker",
		BETTER_AUTH_URL: "http://site.test",
		TBTOP_ADMIN_EMAIL: ADMIN.email,
		TBTOP_ADMIN_PASSWORD: ADMIN.password,
	};
	const pending: Promise<unknown>[] = [];
	const ctx: ExecutionContext = { waitUntil: (task) => void pending.push(task), passThroughOnException: () => {} };
	checks(async () => {
		// The files as the CLI writes them, applied in order as wrangler does.
		const dir = await mkdtemp(join(tmpdir(), "tbtop-worker-"));
		await writeD1Migrations(dir, worker[D1_SCHEMA]);
		for (const file of (await readdir(dir)).sort()) {
			await env.DB.exec(await readFile(join(dir, file), "utf8"));
		}
		return { fetch: (request) => worker.fetch(request, env, ctx) };
	});

	test("a Worker whose D1 files were not applied does not migrate, and says how to", async () => {
		const bare = { ...env, DB: fakeD1() };
		await expect(worker.fetch(new Request("http://site.test/"), bare, ctx)).rejects.toThrow(/tbtop-cms d1:migrations/);
		expect(await bare.DB.prepare("select name from sqlite_master where name = 'tbtop_pages'").all()).toMatchObject({ results: [] });
	});

	test("the cron's scheduled() runs the CMS's tasks", async () => {
		await worker.scheduled({ scheduledTime: Date.now(), cron: "* * * * *" }, env, ctx);
		expect(await pending.at(-1)).toMatchObject({ delivered: 0 });
	});
});

function checks(start: () => Promise<Host>): void {
	const host = start();
	const jar = new Map<string, string>();
	const visit = async (path: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		if (init.inertia === true) {
			headers.set("x-inertia", "true");
		}
		headers.set("cookie", [...jar].map(([k, v]) => `${k}=${v}`).join("; "));
		headers.set("origin", "http://site.test");
		const res = await (await host).fetch(new Request(`http://site.test${path}`, { ...init, headers }));
		for (const line of res.headers.getSetCookie()) {
			const [pair = ""] = line.split(";");
			const at = pair.indexOf("=");
			jar.set(pair.slice(0, at), pair.slice(at + 1));
		}
		return res;
	};
	const jsonLd = (body: string) => [...body.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/g)].map((m) => JSON.parse(m[1] ?? "{}"));

	test("home: the extended `page` type's field, every block, a block's own JSON-LD", async () => {
		const res = await visit("/");
		const body = await res.text();
		expect(res.status).toBe(200);
		expect(body).toContain("<h1>Головна</h1>");
		expect(body).toContain('class="fields">Сайти, що продають<');
		for (const kind of ["Hero", "Promo", "Faq"]) {
			expect(body).toContain(`data-kind="${kind}"`);
		}
		expect(body).toContain("https://divotek.com.ua/audit");
		expect(jsonLd(body).find((node) => node["@type"] === "FAQPage")?.mainEntity?.[0]?.name).toBe("Скільки?");
	});

	test("service page: typed fields, the site's Service JSON-LD, the Organization from `seo` at the identity @id", async () => {
		const body = await (await visit("/audit")).text();
		expect(body).toContain('class="fields">1200 UAH · 2 тижні · Pro · web · seo<');
		const nodes = jsonLd(body);
		const service = nodes.find((node) => node["@type"] === "Service");
		const org = nodes.find((node) => node["@type"] === "Organization");
		expect(service?.offers?.price).toBe(1200);
		expect(org).toMatchObject({ name: "Divotek", telephone: "+380 44 000 00 00" });
		expect(service?.provider?.["@id"]).toBe(org?.["@id"]);
	});

	test("a missing page answers the host's error page, 404", async () => {
		const res = await visit("/nope");
		expect(res.status).toBe(404);
		expect(await res.text()).toContain("<h1>");
	});

	test("a form block posts to its action; the payload is validated by its schema and stored", async () => {
		const body = await (await visit("/")).text();
		const action = body.match(/<form method="post" action="([^"]+)"/)?.[1] ?? "";
		expect(action.startsWith("/_cms/forms/")).toBe(true);
		const post = (payload: Record<string, string>) =>
			visit(action, { method: "POST", headers: { "content-type": "application/json", accept: "application/json" }, body: JSON.stringify(payload) });
		expect((await post({ name: "Ada", phone: "nope" })).status).toBe(422);
		expect((await post({ name: "Ada", phone: "+380 67 123 45 67" })).status).toBe(200);
	});

	test("robots, sitemap and Better Auth's API share the one fetch", async () => {
		expect((await visit("/robots.txt")).status).toBe(200);
		expect((await visit("/sitemap.xml")).status).toBe(200);
		expect((await visit("/api/auth/ok")).status).toBe(200);
	});

	test("public sign-up is off", async () => {
		const res = await visit("/api/auth/sign-up/email", {
			method: "POST",
			headers: { "content-type": "application/json" },
			body: JSON.stringify({ email: "visitor@site.test", password: "visitor-password-1", name: "Visitor" }),
		});
		expect(res.status).toBeGreaterThanOrEqual(400);
	});

	test("admin: the first admin from secrets signs in and sees the settings tabs, the blocks and the host's page", async () => {
		expect((await visit("/admin/pages", { inertia: true })).status).not.toBe(200);
		const login = await visit("/admin/login/forms/login", {
			method: "POST",
			body: JSON.stringify(ADMIN),
			headers: { "content-type": "application/json", referer: "http://site.test/admin/login" },
			inertia: true,
		});
		expect(login.status).toBeLessThan(400);
		const settings = JSON.stringify(await (await visit("/admin/settings/site", { inertia: true })).json());
		for (const text of ['"Company"', '"phone"', '"brandColor"', '"Social"', '"links"']) {
			expect(settings).toContain(text);
		}
		expect(JSON.stringify(await (await visit("/admin/blocks", { inertia: true })).json())).toContain("Callback");
		const callbacks = await visit("/admin/callbacks", { inertia: true });
		expect(callbacks.status).toBe(200);
		expect(JSON.stringify(await callbacks.json())).toContain("+380 67 123 45 67");
	});
}

describe("defineSite", () => {
	test("the catalog is the fields' zod: props a field does not resolve to fail", () => {
		const props = site.catalog.Promo.props;
		expect(props.safeParse({ title: "Hi", cta: null }).success).toBe(true);
		expect(props.safeParse({ title: 1, cta: null }).success).toBe(false);
		expect(site.catalog.Callback.props.safeParse({ heading: null, button: null, action: "/_cms/forms/x" }).success).toBe(true);
	});

	test("a bare field record and pageType() both declare a page type, even with fields named `fields` and `options`", () => {
		const s = defineSite({ pages: { page: { fields: f.text(), options: f.number() }, b: pageType({ a: f.text() }, { label: "B", jsonLd: "Service" }) }, blocks: { X: block({}) } });
		const [page, b] = pageTypeDefs(s.def, { link: () => ({ types: () => null }), pages: () => ({ of: () => null }) });
		expect(Object.keys(page?.schema?.shape ?? {})).toEqual(["fields", "options"]);
		expect(b).toMatchObject({ key: "b", label: "B", schemaTypes: ["Service"] });
		expect(Object.keys(b?.schema?.shape ?? {})).toEqual(["a"]);
	});
});
