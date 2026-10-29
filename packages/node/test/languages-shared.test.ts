import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Fixture } from "../src";
import { makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];

async function panel(fixture?: Fixture) {
	const made = await makeCms(fixture);
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
	return { ...made, admin, visit, page, post };
}

/** The notification an action or form answered with, from its JSON body or the flash on the next visit. */
async function notice(res: Response, page: (url: string) => Promise<InertiaPage>, url: string): Promise<string> {
	if (res.headers.get("content-type")?.includes("json")) {
		return JSON.stringify(await res.json());
	}
	return JSON.stringify(await page(url));
}

describe("shared blocks", () => {
	const fixture: Fixture = {
		languages: en,
		sharedBlocks: [{ key: "cta", name: "CTA", kind: "Hero", data: { title: "Shared" } }],
		pages: [{ key: "home", system: true, name: { en: "Home" } }],
	} as Fixture;

	test("a block a page places cannot be deleted; an unused one can", async () => {
		const { cms, ids } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		expect(await site.sharedBlocks.usage(block.id)).toBe(1);
		await expect(site.sharedBlocks.delete(block.id)).rejects.toThrow("Still placed in 1 place");
		expect(await site.sharedBlocks.find(block.id)).not.toBeNull();

		await site.pages.update(ids.home as number, { content: [] });
		await site.sharedBlocks.delete(block.id);
		expect(await cms.site().sharedBlocks.find(block.id)).toBeNull();
	});

	test("layouts and trashed pages count as placements; string ids count, data does not", async () => {
		const { cms, ids } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.layouts.save("header", [{ id: "h", kind: "Hero", shared: String(block.id) }]);
		const other = await site.pages.create({ type: "page", name: { en: "Other" } });
		await site.pages.update(other.id, { content: [{ id: "b", kind: "Hero", shared: block.id }] as never });
		await site.pages.delete(other.id);
		await site.pages.update(ids.home as number, { content: [{ id: "c", kind: "Hero", data: { shared: block.id } }] as never });
		expect(await site.sharedBlocks.usage(block.id)).toBe(2);
		expect((await site.sharedBlocks.usageCounts()).get(block.id)).toBe(2);
	});

	test("the admin delete refuses a placed block with an error notice", async () => {
		const { cms, ids, post, page } = await panel(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		const res = await post("/admin/shared-blocks/actions/delete", { payload: { row: { id: block.id } } }, "/admin/shared-blocks");
		expect(await notice(res, page, "/admin/shared-blocks")).toContain("Still placed in 1 place");
		expect(await cms.site().sharedBlocks.find(block.id)).not.toBeNull();
	});

	test("the list shows how many places use each block", async () => {
		const { cms, ids, visit } = await panel(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		const res = await visit("/admin/shared-blocks/tables/sharedBlocks", { inertia: false });
		const rows = ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(JSON.stringify(rows)).toContain("Placed in 1 place");
	});

	test("a save with a stale lock version is a conflict and changes nothing", async () => {
		const { cms } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.sharedBlocks.update(block.id, { name: "First" }, { lockVersion: block.lockVersion });
		await expect(site.sharedBlocks.update(block.id, { name: "Second" }, { lockVersion: block.lockVersion })).rejects.toThrow("changed by someone else");
		expect((await cms.site().sharedBlocks.find(block.id))?.name).toBe("First");
	});

	test("the edit form carries the lock version and a stale submit is refused", async () => {
		const { cms, page, post } = await panel(fixture);
		const [block] = await cms.site().sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		const url = `/admin/shared-blocks/${block.id}/edit`;
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).sharedBlock ?? {};
		expect(record.lock_version).toBe(block.lockVersion);
		await cms.site().sharedBlocks.update(block.id, { name: "Theirs" });
		const res = await post(`${url}/forms/sharedBlock`, { ...record, shared_name: "Mine" }, url);
		expect(await notice(res, page, url)).toContain("changed by someone else");
		expect((await cms.site().sharedBlocks.find(block.id))?.name).toBe("Theirs");
	});
});

describe("languages", () => {
	test("a new language gets its og_locale from the catalog; a blank one stays blank outside it", async () => {
		const { cms } = await makeCms({ languages: en, pages: [] });
		const languages = cms.site().languages;
		await languages.create({ name: "Deutsch", locale: "de" });
		await languages.create({ name: "Norsk", locale: "no", isAdminActive: true });
		await languages.create({ name: "Custom", locale: "xx" });
		expect(await languages.ogLocale("de")).toBe("de_DE");
		expect(await languages.ogLocale("no")).toBe("nb_NO");
		expect(await languages.ogLocale("xx")).toBeNull();
	});

	test("og_locale must be language_TERRITORY", async () => {
		const { cms } = await makeCms({ languages: en, pages: [] });
		const languages = cms.site().languages;
		await expect(languages.create({ name: "Deutsch", locale: "de", ogLocale: "de-DE" })).rejects.toThrow("language_TERRITORY");
		const en1 = await languages.find("en");
		await expect(languages.update(en1?.id ?? 0, { ogLocale: "english" })).rejects.toThrow("language_TERRITORY");
		await languages.update(en1?.id ?? 0, { ogLocale: "en_GB" });
		expect(await languages.ogLocale("en")).toBe("en_GB");
		await languages.update(en1?.id ?? 0, { ogLocale: "" });
		expect(await languages.ogLocale("en")).toBeNull();
	});

	test("a language cannot be live on the site while the admin is off; admin off turns the site off", async () => {
		const { cms } = await makeCms({ languages: en, pages: [] });
		const languages = cms.site().languages;
		await expect(languages.create({ name: "Deutsch", locale: "de", isAdminActive: false, isFrontendActive: true })).rejects.toThrow(
			"cannot be frontend-active without being admin-active",
		);
		const de = await languages.create({ name: "Deutsch", locale: "de", isAdminActive: true, isFrontendActive: true });
		await languages.update(de.id, { isAdminActive: false, isFrontendActive: true });
		expect(await languages.find("de")).toMatchObject({ is_admin_active: false, is_frontend_active: false });
		await expect(languages.update(de.id, { isFrontendActive: true })).rejects.toThrow("cannot be frontend-active");
	});

	test("the default stays admin- and frontend-active", async () => {
		const { cms } = await makeCms({ languages: en, pages: [] });
		const languages = cms.site().languages;
		const en1 = await languages.find("en");
		await languages.update(en1?.id ?? 0, { isAdminActive: false, isFrontendActive: false });
		expect(await languages.find("en")).toMatchObject({ is_admin_active: true, is_frontend_active: true });
	});

	test("re-adding a soft-deleted locale restores its row", async () => {
		const { cms, db } = await makeCms({ languages: [...en, { locale: "de", name: "Deutsch", ogLocale: "de_AT" }], pages: [] });
		await db.updateTable("tbtop_languages").set({ deleted_at: "2026-01-01 00:00:00" }).where("locale", "=", "de").execute();
		const languages = cms.site().languages;
		languages.forget();
		expect(await languages.find("de")).toBeNull();
		const restored = await languages.create({ name: "German", locale: "de", isAdminActive: true });
		const rows = await db.selectFrom("tbtop_languages").selectAll().where("locale", "=", "de").execute();
		expect(rows).toHaveLength(1);
		expect(Number(rows[0]?.id)).toBe(restored.id);
		expect(rows[0]?.deleted_at).toBeNull();
		expect(await languages.find("de")).toMatchObject({ name: "German", og_locale: "de_AT", is_admin_active: true });
	});

	test("the admin add form restores a trashed locale and reports invariant errors", async () => {
		const { cms, db, post, page } = await panel({ languages: [...en, { locale: "de", name: "Deutsch" }], pages: [] });
		await db.updateTable("tbtop_languages").set({ deleted_at: "2026-01-01 00:00:00" }).where("locale", "=", "de").execute();
		const bad = await post(
			"/admin/languages/new/forms/language",
			{ name: "Français", locale: "fr", is_admin_active: false, is_frontend_active: true, sort_order: 0 },
			"/admin/languages/new",
		);
		expect(await notice(bad, page, "/admin/languages/new")).toContain("cannot be frontend-active");
		const ok = await post(
			"/admin/languages/new/forms/language",
			{ name: "Deutsch", locale: "de", is_admin_active: true, is_frontend_active: false, sort_order: 1 },
			"/admin/languages/new",
		);
		expect(ok.status).toBeLessThan(400);
		cms.site().languages.forget();
		expect((await cms.site().languages.find("de"))?.og_locale).toBe("de_DE");
	});
});
