import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { betterAuth } from "better-auth";
import { getMigrations } from "better-auth/db/migration";
import { admin as adminPlugin } from "better-auth/plugins";
import type { Kysely } from "kysely";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, createCms, defineBlocks, demoFixture, implementBlocks, linkField, migrateCms, seedFixtures } from "../src";
import { cmsAccess, cmsAuth, cmsRoles } from "../src/betterAuth";
import { freshDb, testDialect } from "./support";

const PASSWORD = "correct-horse";

const catalog = defineBlocks({
	...cmsBlocks,
	Promo: { label: "Promo", props: z.object({ cta: z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable() }) },
});

/** createAdmin(cms.admin.options()) under `/cp`, with Better Auth given once to createCms. */
async function cpPanel() {
	const db = await freshDb();
	await migrateCms(db as Kysely<unknown>, { dialect: testDialect, userKey: "string" });
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
	await better.api.createUser({ body: { email: "owner@site.test", password: PASSWORD, name: "owner", role: "admin" } });
	const cms = createCms({
		db,
		blocks: implementBlocks(catalog, { ...cmsBlockImpls, Promo: { form: () => [linkField("cta").label("Button")] } }),
		url: "https://site.test",
		adminPrefix: "/cp",
		auth: cmsAuth(better),
	});
	const ids = await seedFixtures(cms, demoFixture);
	const panel = createAdmin(cms.admin.options());
	let cookie = "";
	const visit = async (url: string, init: RequestInit = {}) => {
		const headers = new Headers(init.headers);
		headers.set("x-inertia", "true");
		headers.set("cookie", cookie);
		const res = await panel.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		const jar = new Map(cookie.split("; ").filter(Boolean).map((pair) => pair.split("=", 2) as [string, string]));
		for (const line of res.headers.getSetCookie()) {
			const [name = "", value = ""] = (line.split(";")[0] ?? "").split("=", 2);
			jar.set(name, value);
		}
		cookie = [...jar].map(([name, value]) => `${name}=${value}`).join("; ");
		return res;
	};
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	return { cms, ids, visit, post, page };
}

describe("cms.admin.options()", () => {
	test("one spread serves the CMS under adminPrefix: guests go to its login, editors save link fields checked by the CMS's rules", async () => {
		const { cms, ids, visit, post, page } = await cpPanel();
		const guest = await visit("/cp/pages");
		expect(guest.status).toBe(303);
		expect(guest.headers.get("location")).toBe("/cp/login");

		const signedIn = await post("/cp/login/forms/login", { email: "owner@site.test", password: PASSWORD }, "/cp/login");
		expect(signedIn.headers.get("location")).toBe("/cp/dashboard");
		expect((await page("/cp/pages")).component).toBeDefined();
		expect(JSON.stringify((await page("/cp/pages")).props)).toContain('"/cp/profile"');

		const id = ids.about as number;
		await cms.site().pages.update(id, { content: [{ id: "promo1", kind: "Promo", active: true, sort: 0, data: {} }] });
		const url = `/cp/pages/${id}/edit`;
		const save = async (cta: unknown) => {
			const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
			await post(`${url}/forms/pageEditor`, { ...record, block_promo1_cta: cta }, url);
			return Object.keys(((await page(url)).props.errors as Record<string, unknown> | undefined) ?? {});
		};
		expect(await save({ type: "external", value: "javascript:alert(1)" })).toEqual(["block_promo1_cta"]);
		expect(await save({ type: "external", value: "https://example.com", label: { en: "Go" } })).toEqual([]);
		const stored = await cms.site().pages.find(id);
		expect(JSON.stringify(stored?.content)).toContain("https://example.com");
	});

	test("pages appends to the CMS's, or filters them as a function", async () => {
		const { cms } = await cpPanel();
		const own = { ...cms.admin.pages[0], path: "host-page" } as (typeof cms.admin.pages)[number];
		const appended = cms.admin.options({ pages: [own] }).pages;
		expect(appended.at(-1)).toBe(own);
		expect(appended.some((page) => page.path === "login")).toBe(true);

		const filtered = cms.admin.options({ pages: (pages) => pages.filter((page) => page.path !== "login") }).pages;
		expect(filtered.length).toBe(appended.length - 2);
		expect(filtered.some((page) => page.path === "login")).toBe(false);
	});
});
