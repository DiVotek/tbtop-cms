import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Kysely } from "kysely";
import { type Fixture, migrateCms } from "../src";
import { get, makeCms, testDialect } from "./support";

const en = [{ locale: "en", name: "English", default: true }];

async function panel(fixture?: Fixture | null, config: Parameters<typeof makeCms>[1] = {}) {
	const made = await makeCms(fixture, config);
	const { cms, db } = made;
	const admin = createAdmin({
		prefix: "/admin",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: kyselyRules(db),
		user: () => ({ id: 1, name: "Admin" }),
		context: cms.admin.context,
	});
	let cookie = "";
	const visit = async (url: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		headers.set("cookie", cookie);
		if (init.inertia !== false) {
			headers.set("x-inertia", "true");
		}
		const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		cookie = res.headers.get("set-cookie")?.split(";")[0] ?? cookie;
		return res;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	/** The notification an action or form answered with, from its JSON body or the flash on the next visit. */
	const notice = async (res: Response, url: string) =>
		res.headers.get("content-type")?.includes("json") ? JSON.stringify(await res.json()) : JSON.stringify(await page(url));
	const rows = async (table: string, url: string, tab = "") => {
		const res = await visit(`${url}/tables/${table}${tab === "" ? "" : `?tab=${tab}`}`, { inertia: false });
		return ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
	};
	return { ...made, admin, visit, page, post, notice, rows };
}

describe("languages as in PHP (q08)", () => {
	test("migrateCms installs English as the default when no language exists, and leaves a site that has one alone", async () => {
		const { cms, db } = await makeCms(null);
		expect(await cms.site().languages.all()).toMatchObject([{ locale: "en", name: "English", og_locale: "en_US", is_default: true, is_admin_active: true, is_frontend_active: true }]);

		await db.deleteFrom("tbtop_languages").execute();
		await migrateCms(db as Kysely<unknown>, { dialect: testDialect, defaultLanguage: "uk" });
		expect((await cms.site().languages.default())?.locale).toBe("uk");
		await migrateCms(db as Kysely<unknown>, { dialect: testDialect });
		expect((await cms.site().languages.all()).map((row) => row.locale)).toEqual(["uk"]);

		await db.deleteFrom("tbtop_languages").execute();
		await migrateCms(db as Kysely<unknown>, { dialect: testDialect, defaultLanguage: false });
		expect(await cms.site().languages.all()).toEqual([]);
	});

	test("a fixture that names another default takes over from the installed one", async () => {
		const { cms } = await makeCms({ languages: [{ locale: "uk", name: "Українська", default: true }], pages: [{ system: true, name: { uk: "Головна" } }] });
		const languages = await cms.site().languages.all();
		expect(languages.filter((row) => row.is_default).map((row) => row.locale)).toEqual(["uk"]);
		expect((await cms.loadPage(get("/"))).type).toBe("page");
	});

	test("a language goes to the trash and back; the default cannot be deleted", async () => {
		const { cms, post, notice, rows } = await panel();
		const url = "/admin/languages";
		const uk = await cms.site().languages.find("uk");
		const english = await cms.site().languages.find("en");
		if (uk === null || english === null) throw new Error("demo languages missing");
		expect((await cms.loadPage(get("/uk"))).type).toBe("page");

		const refused = await post(`${url}/actions/delete`, { payload: { row: { id: english.id } } }, url);
		expect(await notice(refused, url)).toContain("cannot be deleted");
		expect(await cms.site().languages.find("en")).not.toBeNull();

		await post(`${url}/actions/delete`, { payload: { row: { id: uk.id } } }, url);
		expect(await cms.site().languages.find("uk")).toBeNull();
		expect((await cms.loadPage(get("/uk"))).type).toBe("notFound");
		expect((await rows("languages", url)).map((row) => row.locale)).toEqual(["en"]);
		expect((await rows("languages", url, "deleted")).map((row) => row.locale)).toEqual(["uk"]);

		await post(`${url}/actions/restore`, { payload: { row: { id: uk.id } } }, url);
		expect(await cms.site().languages.find("uk")).toMatchObject({ id: uk.id, is_frontend_active: true });
		expect((await cms.loadPage(get("/uk"))).type).toBe("page");

		await cms.site().languages.delete(uk.id);
		await post(`${url}/actions/forceDelete`, { payload: { row: { id: uk.id } } }, url);
		expect(await rows("languages", url, "deleted")).toEqual([]);
		await expect(cms.site().languages.forceDelete(english.id)).rejects.toThrow();
	});

	test("Add language picks a locale from the catalog and takes its name from it", async () => {
		const { cms, page, post, notice } = await panel({ languages: en, pages: [] });
		const url = "/admin/languages/new";
		const form = JSON.stringify((await page(url)).props);
		expect(form).toContain("Deutsch (de)");
		expect(form).not.toContain("English (en)");

		const unknown = await post(`${url}/forms/language`, { locale: "xx", is_admin_active: true, sort_order: 0 }, url);
		expect(await notice(unknown, url)).toContain("pick a language from the list");

		const ok = await post(`${url}/forms/language`, { locale: "de", is_admin_active: true, is_frontend_active: true, sort_order: 1 }, url);
		expect(ok.status).toBeLessThan(400);
		expect(await cms.site().languages.find("de")).toMatchObject({ name: "Deutsch", og_locale: "de_DE", is_frontend_active: true, is_default: false });

		await post(`${url}/forms/language`, { locale: "fr", is_default: true, sort_order: 2 }, url);
		expect(await cms.site().languages.default()).toMatchObject({ locale: "fr", name: "Français", is_frontend_active: true });
	});
});
