import { Database } from "bun:sqlite";
import { describe, expect, test } from "bun:test";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { memoryDisk } from "@tbtop/admin";
import { ADMIN } from "./reference";
import { fakeD1 } from "./fakeD1";
import type { Env, ExecutionContext, ScheduledController } from "./workers";

export type Host = { fetch(request: Request): Promise<Response> };

/** A fresh in-memory SQLite, the stand-in for the host's database (D1 is SQLite too). */
export const memoryDb = () => new Kysely<unknown>({ dialect: new BunSqliteDialect({ database: new Database(":memory:") }) });

/**
 * The same checks for every variant: the reference site (shared/reference.ts)
 * answers through the variant's host as one `fetch`, admin sign-in included.
 * `boot` runs twice on one database: the host's boot (migrations, seed) must be idempotent.
 */
export function prove(name: string, boot: (db: Kysely<unknown>) => Promise<Host>): void {
	const db = memoryDb();
	check(name, async () => {
		await boot(db);
		return boot(db);
	});
}

/** A Worker module (`export default { fetch, scheduled }`) on a fake D1, an in-memory KV and R2. */
export type Worker = {
	fetch(request: Request, env: Env, ctx: ExecutionContext): Promise<Response>;
	scheduled(controller: ScheduledController, env: Env, ctx: ExecutionContext): Promise<void>;
};

export function proveWorker(name: string, worker: Worker): void {
	const kv = new Map<string, string>();
	const disk = memoryDisk("https://media.test");
	const env: Env = {
		DB: fakeD1(),
		CACHE: { get: async (key) => kv.get(key) ?? null, put: async (key, value) => void kv.set(key, value) },
		MEDIA: {
			put: async (key, value) => void (await disk.put(key, value, { contentType: "application/octet-stream" })),
			get: async (key) => {
				const blob = await disk.get(key);
				return blob === null ? null : { blob: async () => blob };
			},
			delete: (key) => disk.delete(key),
		},
		BETTER_AUTH_SECRET: "worker-secret-worker-secret-worker",
		BETTER_AUTH_URL: "http://site.test",
	};
	const pending: Promise<unknown>[] = [];
	const ctx: ExecutionContext = { waitUntil: (promise) => void pending.push(promise), passThroughOnException: () => {} };
	check(name, async () => ({ fetch: (request) => worker.fetch(request, env, ctx) }));
	test(`${name}: the cron's scheduled() delivers and stamps the heartbeat`, async () => {
		await worker.scheduled({ scheduledTime: Date.now(), cron: "* * * * *" }, env, ctx);
		const results = await Promise.all(pending);
		expect(results.at(-1)).toMatchObject({ delivered: 0 });
	});
}

function check(name: string, start: () => Promise<Host>): void {
	describe(name, () => {
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

		test("home: extended `page` type field, Hero and Promo blocks", async () => {
			const res = await visit("/");
			const body = await res.text();
			expect(res.status).toBe(200);
			expect(body).toContain("<h1>Головна</h1>");
			expect(body).toContain('class="extra">Сайти, що продають<');
			expect(body).toContain('data-kind="Hero"');
			expect(body).toContain("https://divotek.com.ua/audit");
		});

		test("service page: typed fields, host Service schema, host Organization at the identity @id", async () => {
			const res = await visit("/audit");
			const body = await res.text();
			expect(res.status).toBe(200);
			expect(body).toContain('class="extra">1200 UAH · 2 тижні<');
			const nodes = [...body.matchAll(/<script type="application\/ld\+json">(.*?)<\/script>/g)].map((m) => JSON.parse(m[1] ?? "{}"));
			const service = nodes.find((node) => node["@type"] === "Service");
			const org = nodes.find((node) => node["@type"] === "Organization");
			expect(service?.offers?.price).toBe(1200);
			expect(org?.sameAs).toEqual(["https://github.com/DiVotek"]);
			expect(service?.provider?.["@id"]).toBe(org?.["@id"]);
		});

		test("CMS routes and Better Auth's API share the one fetch", async () => {
			expect((await visit("/robots.txt")).status).toBe(200);
			expect((await visit("/sitemap.xml")).status).toBe(200);
			expect((await visit("/api/auth/ok")).status).toBe(200);
		});

		test("admin: seeded admin signs in, sees the host's settings tab and block", async () => {
			expect((await visit("/admin/pages", { inertia: true })).status).not.toBe(200);
			const login = await visit("/admin/login/forms/login", {
				method: "POST",
				body: JSON.stringify(ADMIN),
				headers: { "content-type": "application/json", referer: "http://site.test/admin/login" },
				inertia: true,
			});
			expect(login.status).toBeLessThan(400);
			const settings = await visit("/admin/settings/site", { inertia: true });
			expect(settings.status).toBe(200);
			const json = JSON.stringify(await settings.json());
			expect(json).toContain('"Company"');
			expect(json).toContain('"phone"');
			const blocks = JSON.stringify(await (await visit("/admin/blocks", { inertia: true })).json());
			expect(blocks).toContain("Promo");
		});
	});
}
