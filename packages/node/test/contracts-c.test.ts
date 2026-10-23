import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Kysely } from "kysely";
import { createElement } from "react";
import { z } from "zod";
import { renderToString } from "react-dom/server";
import { cmsBlockImpls, cmsBlocks, type Fixture, implementBlocks, migrateCms, type PageProps } from "../src";
import { GtmNoscript, seoHead, seoTags } from "../src/react";
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

const SNIPPET = `<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];})(window,document,'script','dataLayer','gtm-ab12cd');</script>
<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-AB12CD"></iframe></noscript>`;

async function homeSeo(cms: Awaited<ReturnType<typeof makeCms>>["cms"]): Promise<PageProps["seo"]> {
	const result = await cms.loadPage(get("/"));
	if (result.type !== "page") throw new Error(`expected a page, got ${result.type}`);
	return result.props.seo;
}

describe("Google Tag Manager (q10)", () => {
	test("the SEO settings store the id from a pasted install snippet, and the seo prop carries it", async () => {
		const { cms, post } = await panel();
		expect((await homeSeo(cms)).gtmContainerId).toBeNull();
		const url = "/admin/settings/seo";
		const res = await post(`${url}/forms/settings_seo`, { seo_gtm_container_id: SNIPPET }, url);
		expect(res.status).toBeLessThan(400);
		expect(await cms.site().settings.get("seo.gtm_container_id")).toBe("GTM-AB12CD");
		expect((await homeSeo(cms)).gtmContainerId).toBe("GTM-AB12CD");
	});

	test("a stored value that is not a container id gives null; a stored snippet still resolves", async () => {
		const { cms } = await makeCms();
		await cms.site().settings.setMany({ "seo.gtm_container_id": "not-a-container-GTM-ABC123" });
		expect((await homeSeo(cms)).gtmContainerId).toBeNull();
		await cms.site().settings.setMany({ "seo.gtm_container_id": "GTM-X'};alert(1)//" });
		expect((await homeSeo(cms)).gtmContainerId).toBeNull();
		await cms.site().settings.setMany({ "seo.gtm_container_id": SNIPPET });
		expect((await homeSeo(cms)).gtmContainerId).toBe("GTM-AB12CD");
	});

	test("@tbtop/cms/react puts the loader first in the head and renders the noscript half", async () => {
		const { cms } = await makeCms();
		await cms.site().settings.setMany({ "seo.gtm_container_id": "GTM-AB12CD" });
		const seo = await homeSeo(cms);
		const [first] = seoTags(seo);
		expect(first).toMatchObject({ tag: "script", attrs: {} });
		expect(first?.tag === "script" ? first.children : "").toContain("'dataLayer','GTM-AB12CD'");
		expect(seoHead(seo).scripts[0]).toEqual({ children: expect.stringContaining("googletagmanager.com/gtm.js") });
		expect(renderToString(createElement(GtmNoscript, { id: seo.gtmContainerId }))).toBe(
			'<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-AB12CD" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>',
		);

		const off = { ...seo, gtmContainerId: null };
		expect(seoTags(off).some((tag) => tag.tag === "script" && tag.children.includes("googletagmanager"))).toBe(false);
		expect(renderToString(createElement(GtmNoscript, { id: null }))).toBe("");
	});
});

describe("placements by page type and subtype (q17)", () => {
	const text = z.object({ text: z.string().nullable() });
	const form = { form: (s: import("@tbtop/admin").S) => [s.text("text").label("Text")] };
	const blocks = () =>
		implementBlocks(
			{
				...cmsBlocks,
				ArticleOnly: { props: text, placements: ["article"] },
				NewsOnly: { props: text, placements: ["news"] },
				Banner: { props: text, placements: ["layout"] },
			},
			{ ...cmsBlockImpls, ArticleOnly: form, NewsOnly: form, Banner: form },
		);

	test("the Add block picker offers a kind on the page types and subtypes it names, and blockAdd refuses the others", async () => {
		const { cms, ids, page, post } = await panel(undefined, { blocks: blocks(), pageTypes: [{ key: "news", base: "article" }] });
		const pages = cms.site().pages;
		const article = await pages.create({ type: "article", parentId: ids.blog as number, name: { en: "Plain" } });
		const news = await pages.create({ type: "article", subtype: "news", parentId: ids.blog as number, name: { en: "News" } });
		const offered = async (id: number) => {
			const props = JSON.stringify((await page(`/admin/pages/${id}/edit`)).props);
			return ["Hero", "ContactForm", "ArticleOnly", "NewsOnly", "Banner"].filter((kind) => props.includes(`"value":"${kind}"`));
		};
		expect(await offered(ids.about as number)).toEqual(["Hero", "ContactForm"]);
		expect(await offered(article.id)).toEqual(["Hero", "ContactForm", "ArticleOnly"]);
		expect(await offered(news.id)).toEqual(["Hero", "ContactForm", "ArticleOnly", "NewsOnly"]);
		const header = JSON.stringify((await page("/admin/layout/header")).props);
		expect(header).toContain('"value":"Banner"');
		expect(header).not.toContain('"value":"ArticleOnly"');

		const url = `/admin/pages/${article.id}/edit`;
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
		const refused = await post(`${url}/actions/blockAdd`, { payload: { form: { ...record, new_block_kind: "NewsOnly" } } }, url);
		expect(JSON.stringify(await refused.json())).toContain("Pick a block kind first.");
		const added = await post(`${url}/actions/blockAdd`, { payload: { form: { ...record, new_block_kind: "ArticleOnly" } } }, url);
		expect(added.status).toBe(200);
		expect(((await cms.site().pages.find(article.id))?.content ?? []).map((row) => (row as { kind: string }).kind)).toEqual(["ArticleOnly"]);
	});
});
