import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage, S } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { z } from "zod";
import { blockEditor, type CmsConfig, cmsBlockImpls, cmsBlocks, definePageType, implementBlocks, PageRuleError, templateEditor } from "../src";
import { get, makeCms } from "./support";

/** A shop's records, as the source() of the test types reads them: id → name, or gone. */
const records = new Map<string, string | null>([
	["p1", "Basic tee"],
	["p2", "Hoodie"],
]);

const product = definePageType({
	key: "product",
	label: "Product",
	allowedChildren: ["variant"],
	sourceBacked: true,
	source: (page) => records.get(page.sourceId ?? "") ?? null,
	sourceLinks: (page) => [{ label: "Open product", url: `/shop/products/${page.sourceId}` }],
	requestSeo: (_page, ctx) => (ctx.url?.searchParams.has("filter") ? { noindex: true, canonical: ctx.url.pathname } : null),
	template: () => [{ id: "t1", kind: "Probe", active: true, sort: 0, data: {} }],
});
const variant = definePageType({ key: "variant", requiresParent: true, sourceBacked: true });

/** A block that shows what a resolve reads: the page's source and the request's query. */
const blocks = () =>
	implementBlocks(
		{ ...cmsBlocks, Probe: { props: z.object({ source: z.string().nullable(), query: z.string().nullable() }), placements: ["product", "pages"] } },
		{
			...cmsBlockImpls,
			Probe: { form: (s) => [s.text("note").label("Note")], resolve: (_fields, ctx) => ({ source: (ctx.source as string | null) ?? null, query: ctx.url?.search ?? null }) },
		},
	);

const setup = (config: Partial<CmsConfig> = {}) => makeCms(undefined, { pageTypes: [product, variant], blocks: blocks(), ...config });

const json = (value: unknown) => JSON.stringify(value);

describe("source-backed pages by (type, source_id)", () => {
	test("ensureSource creates the page once: name, slug and parent only then; a racing call leaves one page", async () => {
		const { cms } = await setup();
		const pages = cms.site().pages;
		const first = await pages.ensureSource("product", "p1", { name: { en: "Basic tee", uk: "Футболка" }, slug: "basic-tee" });
		expect(first.created).toBe(true);
		expect([first.page.status, first.page.sourceId, first.page.content]).toEqual(["draft", "p1", null]);
		expect(await pages.slugs.allFor(first.page.id)).toEqual({ en: "basic-tee", uk: "basic-tee" });

		const again = await pages.ensureSource("product", "p1", { name: { en: "Renamed" }, slug: "renamed" });
		expect([again.created, again.page.id]).toEqual([false, first.page.id]);
		expect(await pages.slugs.allFor(first.page.id)).toEqual({ en: "basic-tee", uk: "basic-tee" });

		const child = await pages.ensureSource("variant", "v1", { name: { en: "Black" }, slug: "black", parentId: first.page.id });
		expect(await pages.slugs.pathFor(child.page.id, "en")).toBe("basic-tee/black");

		const raced = await Promise.all([1, 2, 3].map(() => cms.site().pages.ensureSource("product", "p2", { name: { en: "Hoodie" }, slug: "hoodie" })));
		expect(new Set(raced.map((r) => r.page.id)).size).toBe(1);
		expect((await pages.allBySource("product")).map((p) => p.sourceId)).toEqual(["p1", "p2"]);
	});

	test("a colliding slug is de-duplicated; a type that is not source-backed is refused", async () => {
		const { cms } = await setup();
		const pages = cms.site().pages;
		await pages.ensureSource("product", "p1", { name: { en: "A" }, slug: "tee" });
		const second = await pages.ensureSource("product", "p2", { name: { en: "B" }, slug: "tee" });
		expect(await pages.slugs.pathFor(second.page.id, "en")).toBe("tee-2");
		await expect(pages.ensureSource("page", "x", { name: { en: "X" } })).rejects.toBeInstanceOf(PageRuleError);
	});

	test("publish, unpublish and delete by source; the frontend follows", async () => {
		const { cms } = await setup();
		const pages = cms.site().pages;
		await pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee" });
		expect((await cms.loadPage(get("/basic-tee"))).type).toBe("notFound");
		expect((await pages.setSourceStatus("product", "p1", "published"))?.publishedAt).not.toBeNull();
		expect((await cms.loadPage(get("/basic-tee"))).type).toBe("page");
		await pages.setSourceStatus("product", "p1", "draft");
		expect((await cms.loadPage(get("/basic-tee"))).type).toBe("notFound");
		expect(await pages.setSourceStatus("product", "nope", "published")).toBeNull();
		await pages.setSourceStatus("product", "p1", "published");
		expect(await pages.deleteSource("product", "p1")).toBe(true);
		expect(await pages.findBySource("product", "p1")).toBeNull();
		expect((await cms.loadPage(get("/basic-tee"))).type).toBe("notFound");
		expect(await pages.deleteSource("product", "p1")).toBe(false);
		const trashed = await cms.config.db.selectFrom("tbtop_pages").select("id").where("source_id", "=", "p1").where("deleted_at", "is not", null).executeTakeFirst();
		await pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee" });
		await expect(pages.untrash(Number(trashed?.id))).rejects.toThrow("Another page already shows this record.");
	});

	test("a page whose source is gone answers not found; a resolve reads ctx.source and the request URL", async () => {
		const { cms } = await setup();
		const pages = cms.site().pages;
		await pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee", status: "published" });
		const shown = await cms.loadPage(get("/basic-tee?variant=v1"));
		if (shown.type !== "page") throw new Error(shown.type);
		expect(json(shown.props.render)).toContain('"source":"Basic tee","query":"?variant=v1"');

		records.set("p1", null);
		try {
			expect((await cms.loadPage(get("/basic-tee"))).type).toBe("notFound");
		} finally {
			records.set("p1", "Basic tee");
		}
	});

	test("a page type's requestSeo sets noindex and the canonical for this request only", async () => {
		const { cms } = await setup();
		await cms.site().pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee", status: "published" });
		const plain = await cms.loadPage(get("/basic-tee"));
		const filtered = await cms.loadPage(get("/basic-tee?filter=color:black"));
		if (plain.type !== "page" || filtered.type !== "page") throw new Error("not a page");
		expect([plain.props.seo.noindex, plain.headers["X-Robots-Tag"]]).toEqual([false, undefined]);
		expect([filtered.props.seo.noindex, filtered.headers["X-Robots-Tag"]]).toEqual([true, "noindex"]);
		expect([filtered.props.seo.canonical, filtered.props.seo.ogUrl]).toEqual(["https://site.test/basic-tee", "https://site.test/basic-tee"]);
	});

	test("source() only on a source-backed type", () => {
		expect(() => makeCms(null, { pageTypes: [definePageType({ key: "odd", source: () => null })] })).toThrow("source() needs sourceBacked");
	});
});

describe("stored page templates", () => {
	test("a page with no blocks renders its type's stored template, which wins over the code one; reset goes back", async () => {
		const { cms } = await setup();
		const site = cms.site();
		await site.pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee", status: "published" });
		const render = async () => {
			const result = await cms.loadPage(get("/basic-tee"));
			if (result.type !== "page") throw new Error(result.type);
			return json(result.props.render);
		};
		expect(await render()).toContain('"Probe"');
		await site.templates.save("product", [{ id: "h1", kind: "Hero", active: true, sort: 0, data: { title: { en: "Stored" } } }]);
		expect(await render()).toContain("Stored");
		expect(await render()).not.toContain('"Probe"');
		await site.templates.reset("product");
		expect(await render()).toContain('"Probe"');
		await expect(site.templates.save("nope", [])).rejects.toThrow("not registered");
	});
});

async function panel(config: Partial<CmsConfig> = {}) {
	const made = await setup(config);
	const { cms, db } = made;
	const own = {
		path: "templates/$type",
		title: "Template",
		view: async (s: S, { params, request }: { params: Record<string, string>; request: Request }) =>
			templateEditor(cms, s, request, { type: params.type ?? "", back: `/admin/templates/${params.type}` }),
	};
	const admin = createAdmin({
		prefix: "/admin",
		pages: [...cms.admin.pages, own as never],
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: kyselyRules(db),
		user: () => ({ id: 1, name: "Admin" }),
		context: cms.admin.context,
	});
	const visit = (url: string, init: RequestInit = {}) => {
		const headers = new Headers(init.headers);
		headers.set("x-inertia", "true");
		return admin.handle(new Request(`http://x${url}`, { ...init, headers })) as Promise<Response>;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const post = async (url: string, form: string, body: Record<string, unknown>) =>
		visit(`${url}/forms/${form}`, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${url}` } });
	return { ...made, visit, page, post };
}

describe("the admin around source-backed pages", () => {
	test("New page does not offer a source-backed type; the editor shows the Source card, no blocks, no type select, no delete", async () => {
		const { cms, page, post } = await panel();
		const list = json((await page("/admin/pages")).props);
		expect(list).toContain('"value":"page"');
		expect(list).not.toContain('"value":"product"');

		const { page: made } = await cms.site().pages.ensureSource("product", "p1", { name: { en: "Basic tee" }, slug: "basic-tee" });
		const url = `/admin/pages/${made.id}/edit`;
		const editor = await page(url);
		const tree = json(editor.props.structure);
		expect(tree).toContain('"title":"Source"');
		expect(tree).toContain("Open product");
		expect(tree).toContain("/shop/products/p1");
		expect(tree).not.toContain("+ Add block");
		expect(tree).not.toContain('"name":"type"');
		expect(tree).not.toContain("pageEditDelete");

		const record = (editor.props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
		expect((await post(url, "pageEditor", { ...record, heading: { en: "Our best tee" } })).status).toBe(303);
		const saved = await cms.site().pages.find(made.id);
		expect([saved?.heading, saved?.content]).toEqual([{ en: "Our best tee" }, null]);
		await expect(cms.site().pages.duplicate(made.id)).rejects.toBeInstanceOf(PageRuleError);
	});

	test("templateEditor edits a type's stored template with the block editor: it starts from the code template and Save stores it", async () => {
		const { cms, page, post } = await panel();
		const url = "/admin/templates/product";
		const shown = await page(url);
		const tree = json(shown.props.structure);
		expect(tree).toContain("+ Add block");
		expect(tree).toContain('"name":"block_t1_note"');
		const record = (shown.props.data as Record<string, Record<string, unknown>>).template_product ?? {};
		expect((await post(url, "template_product", { ...record, block_t1_note: "Hello" })).status).toBe(303);
		expect(await cms.site().templates.get("product")).toEqual([{ id: "t1", kind: "Probe", active: true, sort: 0, data: { note: "Hello" } }]);
	});

	test("templateEditor needs the design ability, as the layouts do", async () => {
		const { visit } = await panel({ authorize: (_user, ability) => ability !== "tbtop-cms.design" });
		expect((await visit("/admin/templates/product")).status).toBe(403);
		expect((await (await panel()).visit("/admin/templates/nope")).status).toBe(404);
	});

	test("blockEditor saves wherever the host keeps the rows", async () => {
		const { cms } = await setup();
		let saved: unknown = null;
		const form = (await blockEditor(cms, new S(), get("/"), {
			id: "own",
			content: [{ id: "a", kind: "Hero", active: true, sort: 0, data: {} }],
			placement: ["pages"],
			back: "/admin/own",
			save: async (rows) => {
				saved = rows;
			},
		})) as { toJSON?: () => unknown };
		expect(json(form)).toContain("block_a_active");
		expect(saved).toBeNull();
	});
});
