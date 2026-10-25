import { describe, expect, test } from "bun:test";
import { decodeJson } from "../src/support/values";
import { panel } from "./panel";

const table = async (visit: Awaited<ReturnType<typeof panel>>["visit"], query = "") =>
	(await (await visit(`/admin/pages/tables/pages${query}`, { inertia: false })).json()) as { data: { data: Record<string, unknown>[]; tabCounts?: Record<string, number> } };

describe("the pages list (PagesPage)", () => {
	test("New page is a header action; tabs per live type and Deleted carry counts", async () => {
		const { page, visit, cms, ids } = await panel();
		const props = (await page("/admin/pages")).props as { headerActions?: { name: string }[]; structure: unknown };
		expect(props.headerActions?.map((action) => action.name)).toEqual(["pageCreate"]);
		expect(JSON.stringify(props.structure)).not.toContain('"name":"pageCreate"');

		await cms.site().pages.delete(ids.draft as number);
		const res = await table(visit);
		expect(res.data.tabCounts).toMatchObject({ deleted: 1 });
		expect(res.data.tabCounts?.all).toBe((res.data.tabCounts?.page ?? 0) + (res.data.tabCounts?.category ?? 0) + (res.data.tabCounts?.article ?? 0));
		expect(res.data.data.map((row) => Number(row.id))).not.toContain(ids.draft);
		const trashed = (await table(visit, "?tab=deleted")).data.data;
		expect(trashed.map((row) => Number(row.id))).toEqual([Number(ids.draft)]);
		expect(trashed[0]?._recordUrl).toBeNull();
	});

	test("rows carry the display state, an SEO badge and the path; search finds a name", async () => {
		const { visit, cms, ids } = await panel();
		await cms.site().pages.update(ids.about as number, { summary: { en: "" }, isIndexable: false });
		const rows = (await table(visit, "?search=About")).data.data;
		expect(rows.map((row) => [row.name, row.display_state, row.seo_state, row.path])).toEqual([["About us", "Published", "Needs work", "about-us"]]);
		expect((rows[0]?._tooltips as Record<string, string>).seo_state).toBe("No summary\nExcluded from indexing");
		const drafts = (await table(visit)).data.data.filter((row) => Number(row.id) === ids.draft);
		expect(drafts.map((row) => row.display_state)).toEqual(["Draft"]);
	});

	test("duplicate makes a draft copy; restore and delete permanently act on the trash", async () => {
		const { post, cms, db, ids } = await panel();
		const act = (name: string, key: unknown) => post(`/admin/pages/actions/${name}`, { payload: { row: { _key: key } } }, "/admin/pages");
		const pages = cms.site().pages;

		expect((await act("pageDuplicate", ids.about)).status).toBe(200);
		const copy = await db.selectFrom("tbtop_pages").select(["id", "name", "status", "parent_id"]).orderBy("id", "desc").executeTakeFirstOrThrow();
		expect([(decodeJson(copy.name) as { en: string }).en, copy.status]).toEqual(["About us (copy)", "draft"]);
		expect(await pages.slugs.pathFor(Number(copy.id), "en")).toBe("about-us-copy");

		await pages.delete(Number(copy.id));
		expect(await pages.slugs.pathFor(Number(copy.id), "en")).toBeNull();
		expect((await act("pageRestore", copy.id)).status).toBe(200);
		expect((await pages.find(Number(copy.id)))?.id).toBe(Number(copy.id));
		expect(await pages.slugs.pathFor(Number(copy.id), "en")).toBe("about-us-copy");

		await pages.delete(Number(copy.id));
		expect((await act("pageForceDelete", copy.id)).status).toBe(200);
		expect(await db.selectFrom("tbtop_pages").select("id").where("id", "=", copy.id).executeTakeFirst()).toBeUndefined();
		const audit = await db.selectFrom("activity_log").select("event").where("subject_id", "=", copy.id).orderBy("id").execute();
		// The trashing above ran outside an admin request, so only the actions are on record.
		expect(audit.map((row) => row.event)).toEqual(["duplicated", "restored", "force_deleted"]);
	});

	test("a page whose parent is in the trash is not restored", async () => {
		const { cms, ids } = await panel();
		const pages = cms.site().pages;
		await pages.delete(ids["first-post"] as number);
		await pages.delete(ids["scheduled-post"] as number);
		await pages.delete(ids.blog as number);
		await expect(pages.untrash(ids["first-post"] as number)).rejects.toThrow("Restore the parent page first.");
	});
});

describe("the Blocks showcase (BlocksPage)", () => {
	test("a card per kind with its usage, and the kind's fields in Details", async () => {
		const { page } = await panel();
		const tree = JSON.stringify((await page("/admin/blocks")).props.structure);
		expect(tree).toContain('"name":"blockDetailsHero"');
		expect(tree).toMatch(/Used on \d+ pages?/);
		expect(tree).toContain("data-block-preview");
		expect(tree).toContain("Placements");
	});
});

describe("the audit log (AuditLogPage)", () => {
	test("lists the admin's changes and opens one entry", async () => {
		const { post, visit, page, ids } = await panel();
		await post("/admin/pages/actions/pageDuplicate", { payload: { row: { _key: ids.about } } }, "/admin/pages");
		const rows = ((await (await visit("/admin/audit/tables/auditActivities", { inertia: false })).json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(rows[0]).toMatchObject({ causer_id: "User #1", event: "Duplicated", subject_type: "Page", properties: "About us (copy)" });
		const entry = await page(`/admin/audit/${String(rows[0]?.id)}`);
		expect(entry.props.title).toBe("Duplicated");
		expect(JSON.stringify(entry.props.structure)).toContain("source_page_id");
	});
});

describe("site health (SiteHealthPage)", () => {
	test("a row per check with its status", async () => {
		const { page } = await panel();
		const tree = JSON.stringify((await page("/admin/site-health")).props.structure);
		for (const label of ["Site URL", "Default language", "Block rows"]) {
			expect(tree).toContain(label);
		}
	});
});

describe("layouts and settings (LayoutPage, SettingsPageBase)", () => {
	test("a card per slot; the editors end with Cancel and Save on the right", async () => {
		const { page } = await panel();
		const index = JSON.stringify((await page("/admin/layout")).props.structure);
		expect(index).toContain("Blocks rendered above the page content on every page.");
		expect(index).toContain('"name":"edit-header"');
		const edit = await page("/admin/layout/header");
		expect(edit.props.title).toBe("Edit Header layout");
		for (const url of ["/admin/layout/header", "/admin/settings/site"]) {
			const tree = JSON.stringify((await page(url)).props.structure);
			expect(tree).toMatch(/"kind":"spacer".{0,200}"name":"cancel".{0,400}"name":"save"/);
		}
		const site = await page("/admin/settings/site");
		expect([site.props.title, site.props.subtitle]).toEqual(["Site settings", "General site identity: name, logo, and favicon."]);
	});
});
