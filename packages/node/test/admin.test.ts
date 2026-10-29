import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage, type S } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { panel as hostPanel } from "./panel";
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
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
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
			"/admin/form-submissions",
			"/admin/notification-settings",
			"/admin/blocks",
			"/admin/site-health",
			"/admin/audit",
		]) {
			const res = await page(url);
			expect([url, res.component]).toEqual([url, "admin/page"]);
		}
		const nav = JSON.stringify((await page("/admin/pages")).props);
		const items = (JSON.parse(nav) as { tbtop: { nav: { key: string; items: { label: string }[] }[] } }).tbtop.nav.map((group) => [group.key, group.items.map((item) => item.label)]);
		// CmsPanel's items, minus the media library (Node has none); the inbox is a header link, not a nav item.
		expect(items).toEqual([
			["content", ["Pages", "Collections", "Redirects", "404 log", "SEO report"]],
			["design", ["Layouts", "Blocks", "Shared blocks"]],
			["settings", ["Languages", "Site settings", "SEO settings", "Notifications", "Audit log", "Site health"]],
		]);
	});

	test("the pages table lists pages with their type and path", async () => {
		const { visit } = await panel();
		const res = await visit("/admin/pages/tables/pages", { inertia: false });
		const rows = ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(rows.map((row) => row.path)).toContain("blog/first-post");
		expect(rows.find((row) => row.path === "blog/first-post")?.type).toBe("Article");
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
		const { cms, post, page: open, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		// A published page validates the editor form before a structural action, so the action carries it.
		const record = ((await open(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
		const added = await post(`${url}/actions/blockAdd_Hero`, { payload: { form: record } }, url);
		expect(added.status).toBeLessThan(400);
		const page = await cms.site().pages.find(ids.about as number);
		expect(page?.content?.map((row) => (row as { kind: string }).kind)).toEqual(["Hero"]);
		const id = (page?.content?.[0] as { id: string }).id;
		const reloaded = ((await open(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
		await post(`${url}/actions/blockRemove_${id}`, { payload: { form: reloaded } }, url);
		expect((await cms.site().pages.find(ids.about as number))?.content).toEqual([]);
	});

	test("site settings save to their dotted keys", async () => {
		const { cms, post } = await panel();
		const res = await post("/admin/settings/site/forms/settings_site", { site_name: "Renamed" }, "/admin/settings/site");
		expect(res.status).toBe(303);
		const settings = cms.site().settings;
		expect(await settings.get("site.name")).toBe("Renamed");
	});

	test("retrying a delivered or deleted submission says so", async () => {
		const { cms, post, ids, db } = await panel();
		const site = cms.site();
		const submission = await site.submissions.create({ pageId: ids.home as number, blockId: "fx000002", kind: "ContactForm", locale: "en", payload: { name: "Ada" } });
		await db.updateTable("tbtop_form_submissions").set({ status: "handled" }).where("id", "=", submission.id).execute();
		const retry = async () => JSON.stringify(await (await post("/admin/form-submissions/actions/retry", { payload: { row: { id: submission.id } } }, "/admin/form-submissions")).json());
		expect(await retry()).toContain("Already delivered.");
		await site.submissions.delete([submission.id]);
		expect(await retry()).toContain("This submission no longer exists.");
	});

	test("the inbox lists a submission; opening it marks it read; a comment and a delete work", async () => {
		const { cms, visit, page, post, ids } = await panel();
		const site = cms.site();
		const submission = await site.submissions.create({
			pageId: ids.home as number,
			blockId: "fx000002",
			kind: "ContactForm",
			locale: "en",
			payload: { name: "Ada", email: "ada@example.test", message: "Hi" },
		});
		const table = await visit("/admin/form-submissions/tables/formSubmissions", { inertia: false });
		const rows = ((await table.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(rows[0]).toMatchObject({ payload: "Ada · ada@example.test", kind: "Contact form", page_id: "Home", lead_status: "new" });

		const url = `/admin/form-submissions/${submission.id}`;
		expect(JSON.stringify((await page(url)).props)).toContain("ada@example.test");
		expect((await site.submissions.findOrFail(submission.id)).leadStatus).toBe("read");

		expect((await post(`${url}/forms/addCommentForm`, { body: "Called back" }, url)).status).toBe(303);
		expect(await site.submissions.comments(submission.id)).toMatchObject([{ userId: 1, body: "Called back" }]);

		await post(`${url}/actions/setStatus_processed`, {}, url);
		expect((await site.submissions.findOrFail(submission.id)).leadStatus).toBe("processed");
		await post(`${url}/actions/deleteSubmission`, {}, url);
		expect(await site.submissions.find(submission.id)).toBeNull();
	});

	test("notification settings save recipients, channels and a Liquid override; a broken one is refused", async () => {
		const { cms, page, post } = await panel();
		const url = "/admin/notification-settings";
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).notification_settings ?? {};
		expect(record.channels__form_submission).toEqual(["bell"]);
		const save = (extra: Record<string, unknown>) =>
			post(`${url}/forms/notification_settings`, { ...record, notification_mail_recipients: ["a@example.test", "b@example.test"], channels__form_submission: ["mail"], ...extra }, url);

		await save({ tpl__form_submission__html__en__subject: "Lead", tpl__form_submission__html__en__body: "{% if x %}" });
		expect(await cms.site().settings.get("notifications.mail.recipients")).toBeNull();

		expect((await save({ tpl__form_submission__html__en__subject: "Lead {{ leadIdentity }}", tpl__form_submission__html__en__body: "<p>{{ pageName }}</p>" })).status).toBe(303);
		const settings = cms.site().settings;
		expect(await settings.get("notifications.mail.recipients")).toBe("a@example.test\nb@example.test");
		expect(await settings.get("notifications.form.submission.channels")).toEqual(["mail"]);
		const rows = await cms.config.db.selectFrom("tbtop_notification_templates").select(["format", "locale", "subject", "body"]).execute();
		expect(rows).toEqual([{ format: "html", locale: "en", subject: "Lead {{ leadIdentity }}", body: "<p>{{ pageName }}</p>" }]);
	});
});

describe("host tabs on Site settings (siteSettings)", () => {
	const brand = { tabs: (s: S) => [{ label: "Brand & forms", children: [s.grid({ cols: 2 }, [s.text("tagline").label("Tagline"), s.text("monogram").label("Monogram")])] }] };

	test("show after the built-in tabs, save at site.<name> and reach the frontend's cms.settings", async () => {
		const { cms, page, post } = await hostPanel(undefined, { siteSettings: brand });
		expect(JSON.stringify((await page("/admin/settings/site")).props.structure)).toContain("Brand & forms");
		const res = await post("/admin/settings/site/forms/settings_site", { site_name: "Acme", tagline: "We build", monogram: "" }, "/admin/settings/site");
		expect(res.status).toBe(303);
		expect(await cms.site().settings.get("site.tagline")).toBe("We build");
		expect(await cms.site().settings.get("site.monogram")).toBeNull();
		const result = await cms.loadPage(get("/"));
		expect(result.type === "page" ? result.props.cms.settings.tagline : null).toBe("We build");
	});

	test("a host media field reaches cms.settings as a URL, like the logo", async () => {
		const media = { tabs: (s: S) => [{ label: "Brand", children: [s.upload("og_logo").label("OG logo")] }] };
		const { cms } = await hostPanel(undefined, { siteSettings: media });
		await cms.site().settings.set("site.og_logo", "brand/og.png");
		const result = await cms.loadPage(get("/"));
		const shipped = result.type === "page" ? result.props.cms.settings.og_logo : null;
		expect(shipped).toBe(cms.site().mediaUrl("brand/og.png"));
		expect(shipped).not.toBe("brand/og.png");
	});

	test("a field name the page already uses is refused", async () => {
		const clash = { tabs: (s: S) => [{ label: "Mine", children: [s.text("site_name")] }] };
		await expect(hostPanel(undefined, { siteSettings: clash })).rejects.toThrow('"site_name" is already on the Site settings page');
		const phones = { tabs: (s: S) => [{ label: "Mine", children: [s.text("phones")] }] };
		await expect(hostPanel(undefined, { siteSettings: phones })).rejects.toThrow('"phones" is already on the Site settings page');
	});
});
