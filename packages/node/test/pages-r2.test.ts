import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { CmsConfig } from "../src";
import { get, makeCms } from "./support";

async function panel(config: Partial<CmsConfig> = {}) {
	const made = await makeCms(undefined, config);
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
	/** The notice a submit flashed, read off the next page view. */
	const flashed = async () => JSON.stringify(((await page("/admin/pages")) as InertiaPage & { flash?: unknown }).flash ?? null);
	const editor = async (id: number) => ((await page(`/admin/pages/${id}/edit`)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
	// The editor's record as first loaded, resubmitted with the stored lock version.
	const records = new Map<number, Record<string, unknown>>();
	const save = async (id: number, changes: Record<string, unknown>) => {
		const url = `/admin/pages/${id}/edit`;
		const record = records.get(id) ?? (await editor(id));
		records.set(id, record);
		const lockVersion = (await cms.site().pages.find(id))?.lockVersion;
		return post(`${url}/forms/pageEditor`, { ...record, lock_version: lockVersion, ...changes }, url);
	};
	return { ...made, admin, visit, page, post, flashed, editor, save };
}

const pageCount = async (cms: Awaited<ReturnType<typeof makeCms>>["cms"]) => (await cms.site().pages.query().select("id").execute()).length;

describe("R2-content-4: articles from the admin", () => {
	test("the create modal takes a category parent and creates an article under it", async () => {
		const { cms, post, ids } = await panel();
		const res = await post("/admin/pages/forms/pageCreate", { name: { en: "News" }, type: "article", parent_id: String(ids.blog) }, "/admin/pages");
		expect(res.status).toBe(303);
		const created = (await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst()) as { id: number; type: string; parent_id: number };
		expect([created.type, Number(created.parent_id)]).toEqual(["article", ids.blog as number]);
		expect(res.headers.get("location")).toBe(`/admin/pages/${created.id}/edit`);
	});

	test("an article without a category is refused with a notice, a page drops a stale parent", async () => {
		const { cms, post, page, ids } = await panel();
		const before = await pageCount(cms);
		const res = await post("/admin/pages/forms/pageCreate", { name: { en: "News" }, type: "article", parent_id: null }, "/admin/pages");
		expect(res.status).toBeLessThan(500);
		expect(await pageCount(cms)).toBe(before);
		expect(JSON.stringify((await page("/admin/pages")).props.errors)).toContain("parent_id");

		await post("/admin/pages/forms/pageCreate", { name: { en: "Plain" }, type: "page", parent_id: String(ids.blog) }, "/admin/pages");
		const plain = (await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst()) as { type: string; parent_id: number | null };
		expect([plain.type, plain.parent_id]).toEqual(["page", null]);
	});

	test("the create modal's subtype select picks the subtype of the chosen base", async () => {
		const { cms, post, ids } = await panel({ pageTypes: [{ key: "news", base: "article" }] });
		await post("/admin/pages/forms/pageCreate", { name: { en: "N" }, type: "article", subtype_article: "news", parent_id: String(ids.blog) }, "/admin/pages");
		const created = (await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst()) as { type: string; subtype: string | null };
		expect([created.type, created.subtype]).toEqual(["article", "news"]);
	});

	test("in the editor a page given a category becomes an article, and back when the category is removed", async () => {
		const { cms, save, editor, ids } = await panel();
		const about = ids.about as number;
		const record = await editor(about);
		expect(record.type).toBe("page");
		expect((await save(about, { parent_id: String(ids.blog) })).status).toBe(303);
		let page = await cms.site().pages.find(about);
		expect([page?.type, page?.parentId]).toEqual(["article", ids.blog as number]);
		expect((await cms.loadPage(get("/blog/about-us"))).type).toBe("page");

		expect((await save(about, { parent_id: null })).status).toBe(303);
		page = await cms.site().pages.find(about);
		expect([page?.type, page?.parentId]).toEqual(["page", null]);
	});

	test("the editor's type select retypes a page, and a page is offered category parents", async () => {
		const { cms, save, page, ids } = await panel();
		const about = ids.about as number;
		const tree = JSON.stringify((await page(`/admin/pages/${about}/edit`)).props);
		const parents = tree.match(/"label":"Parent","options":(\[.*?\])/);
		expect((JSON.parse(parents?.[1] ?? "[]") as { value: string }[]).map((o) => Number(o.value))).toEqual([ids.blog as number]);
		expect(tree).toContain('"name":"type"');
		await save(about, { type: "category" });
		expect((await cms.site().pages.find(about))?.type).toBe("category");
	});
});

describe("R2-content-6: the publishing schedule", () => {
	test("an unpublish time at or before the publish time is refused", async () => {
		const { cms, save, flashed, ids } = await panel();
		const about = ids.about as number;
		const before = await cms.site().pages.find(about);
		const res = await save(about, { status: "published", published_at: "2026-10-01T00:00:00Z", unpublish_at: "2026-09-01T00:00:00Z" });
		expect(res.status).toBeLessThan(500);
		expect(await flashed()).toContain("unpublish time must be after");
		const after = await cms.site().pages.find(about);
		expect([after?.unpublishAt, after?.lockVersion]).toEqual([before?.unpublishAt ?? null, before?.lockVersion]);
	});
});

describe("R2-content-7: unparsable dates", () => {
	test("an unparsable date is saved as empty instead of a 500", async () => {
		const { cms, save, ids } = await panel();
		const about = ids.about as number;
		const res = await save(about, { published_at: "not a date", unpublish_at: "2027-01-01T00:00:00Z" });
		expect(res.status).toBe(303);
		const page = await cms.site().pages.find(about);
		expect(page?.publishedAt).toBeNull();
		expect(page?.unpublishAt?.toISOString()).toBe("2027-01-01T00:00:00.000Z");
	});
});

describe("R2-content-8: a new page's content", () => {
	test("the content starts from the type's template, and no title column is written", async () => {
		const { cms, post } = await panel({
			pageTypes: [{ key: "landing", template: () => [{ kind: "Hero", data: { title: { en: "Hi" } } }, { kind: "Faq", active: false }] }],
		});
		await post("/admin/pages/forms/pageCreate", { name: { en: "Fresh", uk: "Свіжа" }, type: "landing" }, "/admin/pages");
		const page = await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst();
		const created = await cms.site().pages.find(Number(page?.id));
		expect(created?.seo).toEqual({});
		const content = (created?.content ?? []) as Record<string, unknown>[];
		expect(content.map((row) => [row.kind, row.active, row.sort, row.data])).toEqual([
			["Hero", true, 0, { title: { en: "Hi" } }],
			["Faq", false, 1, {}],
		]);
		expect(content.every((row) => typeof row.id === "string" && row.id !== "")).toBe(true);
		expect(new Set(content.map((row) => row.id)).size).toBe(2);
	});

	test("a subtype's own template wins over its base's, on create and on render", async () => {
		const { cms, post } = await panel({
			pageTypes: [
				{ key: "landing", template: () => [{ kind: "Faq" }] },
				{ key: "solution", base: "landing", template: () => [{ id: "tpl1", kind: "Hero", data: { title: { en: "Solution" } } }] },
				{ key: "plain", base: "landing" },
			],
		});
		const created = async (subtype: string) => {
			await post("/admin/pages/forms/pageCreate", { name: { en: subtype }, type: "landing", subtype_landing: subtype }, "/admin/pages");
			const row = await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst();
			return cms.site().pages.find(Number(row?.id));
		};
		const kinds = (content: unknown) => ((content ?? []) as Record<string, unknown>[]).map((row) => row.kind);
		expect(kinds((await created("solution"))?.content)).toEqual(["Hero"]);
		expect(kinds((await created("plain"))?.content)).toEqual(["Faq"]);
		await cms.site().pages.create({ type: "landing", subtype: "solution", status: "published", name: { en: "Seeded" } }, { slugs: { en: "seeded" } });
		const rendered = await cms.loadPage(get("/seeded"));
		expect(rendered.type === "page" ? Object.values(rendered.props.render.elements).map((element) => element.type) : rendered.type).toContain("Hero");
	});

	test("a type without a template starts with no blocks", async () => {
		const { cms, post } = await panel();
		await post("/admin/pages/forms/pageCreate", { name: { en: "Fresh" }, type: "page" }, "/admin/pages");
		const page = await cms.site().pages.query().selectAll().orderBy("id", "desc").executeTakeFirst();
		const created = await cms.site().pages.find(Number(page?.id));
		expect(created?.content).toEqual([]);
	});
});
