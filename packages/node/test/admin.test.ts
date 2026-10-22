import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { get, makeCms } from "./support";

async function panel() {
	const made = await makeCms();
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
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { referer: `http://x${referer}` } });
	return { ...made, admin, visit, page, post };
}

describe("admin pages", () => {
	test("every CMS page renders, the nav has the three groups", async () => {
		const { page, ids } = await panel();
		for (const url of [
			"/admin/pages",
			`/admin/pages/${ids.home}/edit`,
			"/admin/redirects",
			"/admin/layout",
			"/admin/layout/header",
			"/admin/shared-blocks",
			"/admin/shared-blocks/1/edit",
			"/admin/languages",
			"/admin/languages/new",
			"/admin/languages/1/edit",
			"/admin/settings/site",
			"/admin/settings/seo",
		]) {
			const res = await page(url);
			expect([url, res.component]).toEqual([url, "admin/page"]);
		}
		const nav = JSON.stringify((await page("/admin/pages")).props);
		for (const label of ["Pages", "Redirects", "Layout", "Shared blocks", "Languages", "Site", "SEO"]) {
			expect(nav).toContain(`"${label}"`);
		}
	});

	test("the pages table lists pages with their type and path", async () => {
		const { visit } = await panel();
		const res = await visit("/admin/pages/tables/pages", { inertia: false });
		const rows = ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(rows.map((row) => row.path)).toContain("/blog/first-post");
		expect(rows.find((row) => row.path === "/blog/first-post")?.type).toBe("Article");
	});

	test("editing a page saves its fields, block data and slug, then the site shows it", async () => {
		const { cms, post, page, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
		expect(record.name).toEqual({ en: "About us", uk: "Про нас" });
		const res = await post(
			`${url}/forms/pageEditor`,
			{ ...record, name: { en: "About the team", uk: "Про команду" }, slug: { en: "team", uk: "komanda" }, seo_title: { en: "Our team" } },
			url,
		);
		expect(res.status).toBe(303);
		const result = await cms.loadPage(get("/team"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.page.name).toBe("About the team");
		expect(result.props.seo.title).toBe("Our team — tbtop demo");
		expect((await cms.loadPage(get("/uk/komanda"))).type).toBe("page");
	});

	test("adding a block appends a row of that kind; removing it drops it", async () => {
		const { cms, post, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		const added = await post(`${url}/actions/blockAdd`, { payload: { form: { new_block_kind: "Hero", lock_version: 0 } } }, url);
		expect(added.status).toBeLessThan(400);
		const page = await cms.site().pages.find(ids.about as number);
		expect(page?.content?.map((row) => (row as { kind: string }).kind)).toEqual(["Hero"]);
		const id = (page?.content?.[0] as { id: string }).id;
		await post(`${url}/actions/blockRemove_${id}`, { payload: { form: { lock_version: page?.lockVersion } } }, url);
		expect((await cms.site().pages.find(ids.about as number))?.content).toEqual([]);
	});

	test("site settings save to their dotted keys", async () => {
		const { cms, post } = await panel();
		const res = await post("/admin/settings/site/forms/settings_site", { site_name: "Renamed", phones: [{ number: "+1 555 0100" }] }, "/admin/settings/site");
		expect(res.status).toBe(303);
		const settings = cms.site().settings;
		expect(await settings.get("site.name")).toBe("Renamed");
		expect(await settings.get("site.contacts.phones")).toEqual([{ number: "+1 555 0100" }]);
	});
});
