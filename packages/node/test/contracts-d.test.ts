import { describe, expect, expectTypeOf, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { z } from "zod";
import { type Cms, cmsBlockImpls, cmsBlocks, createCms, defineBlocks, definePageType, implementBlocks, type Json, linkField, type PageProps, pagesField } from "../src";
import { get, makeCms } from "./support";

const promoCatalog = defineBlocks({
	...cmsBlocks,
	Promo: {
		label: "Promo",
		props: z.object({
			cta: z.object({ href: z.string(), type: z.string(), label: z.string() }).nullable(),
			related: z.array(z.object({ id: z.string(), name: z.string(), url: z.string() })),
		}),
	},
});

const promoBlocks = () =>
	implementBlocks(promoCatalog, {
		...cmsBlockImpls,
		Promo: { form: () => [linkField("cta").label("Button"), pagesField("related").label("Related").of("article").max(3)] },
	});

async function panel() {
	const made = await makeCms(undefined, { blocks: promoBlocks() });
	const { cms, db } = made;
	const admin = createAdmin({
		prefix: "/admin",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: { ...kyselyRules(db), ...cms.admin.hostRules },
		user: () => ({ id: 1, name: "Admin" }),
		context: cms.admin.context,
	});
	let cookie = "";
	const call = async (url: string, init: RequestInit = {}) => {
		const headers = new Headers(init.headers);
		headers.set("cookie", cookie);
		headers.set("x-inertia", "true");
		const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		cookie = res.headers.get("set-cookie")?.split(";")[0] ?? cookie;
		return res;
	};
	const post = (url: string, body: unknown, referer = url) =>
		call(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", accept: "application/json", referer: `http://x${referer}` } });
	const page = async (url: string) => (await (await call(url)).json()) as InertiaPage;
	/** The about page with one Promo block: its editor URL, and a save that answers the fields the editor refused. */
	const withPromo = async () => {
		const id = made.ids.about as number;
		const blockId = "promo1";
		await cms.site().pages.update(id, { content: [{ id: blockId, kind: "Promo", active: true, sort: 0, data: {} }] });
		const url = `/admin/pages/${id}/edit`;
		const opened = await page(url);
		const save = async (values: Record<string, unknown>) => {
			const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
			await post(`${url}/forms/pageEditor`, { ...record, ...values });
			return Object.keys(((await page(url)).props.errors as Record<string, unknown> | undefined) ?? {}).sort();
		};
		return { url, opened, save, field: (name: string) => `block_${blockId}_${name}` };
	};
	return { ...made, post, page, withPromo };
}

/** Every wire node of a kind in an Inertia page's structure. */
function nodes(value: unknown, kind: string): Record<string, unknown>[] {
	if (Array.isArray(value)) {
		return value.flatMap((item) => nodes(item, kind));
	}
	if (value === null || typeof value !== "object") {
		return [];
	}
	const node = value as Record<string, unknown>;
	return [...(node.kind === kind ? [node] : []), ...Object.values(node).flatMap((child) => nodes(child, kind))];
}

describe("link and pages fields (q16)", () => {
	test("a host block's link and pages fields resolve to {href, type, label} and the live pages", async () => {
		const { cms, ids } = await panel();
		await cms.site().pages.update(ids.about as number, {
			content: [
				{
					id: "promo1",
					kind: "Promo",
					active: true,
					data: {
						cta: { type: "page", page: String(ids["first-post"]), anchor: "intro", label: { uk: "Читати" } },
						related: [String(ids["first-post"]), String(ids.draft), "999", String(ids.blog), String(ids["first-post"])],
					},
				},
			],
		});
		const en = await cms.loadPage(get("/about-us"));
		if (en.type !== "page") throw new Error(en.type);
		expect(en.props.render.elements.promo1?.props).toEqual({
			cta: { href: "/blog/first-post#intro", type: "page", label: "First post" },
			related: [
				{ id: String(ids["first-post"]), name: "First post", url: "/blog/first-post" },
				{ id: String(ids.blog), name: "Blog", url: "/blog" },
			],
		});
		const uk = await cms.loadPage(get("/uk/pro-nas"));
		if (uk.type !== "page") throw new Error(uk.type);
		expect(uk.props.render.elements.promo1?.props.cta).toEqual({ href: "/uk/blog/persii-zapis#intro", type: "page", label: "Читати" });
	});

	test("the editor renders the link kind with its search endpoint and the pages field as an async multi-select", async () => {
		const { withPromo } = await panel();
		const { opened, field } = await withPromo();
		const link = nodes(opened.props, "link").find((node) => node.name === field("cta"));
		expect(link?.options).toMatchObject({ searchEndpoint: "/admin/link/page-search/select-options/page", locales: ["en", "uk"], defaultLocale: "en" });
		const pages = nodes(opened.props, "select").find((node) => node.name === field("related"));
		expect(pages?.options).toMatchObject({ multiple: true, searchable: true, async: true });
	});

	test("the pages field searches pages of its types by name, drafts included", async () => {
		const { post, withPromo, ids } = await panel();
		const { url, field } = await withPromo();
		const search = async (term: string) =>
			((await (await post(`${url}/select-options/${field("related")}`, { search: term })).json()) as { options: { value: string; label: string }[] }).options;
		expect(await search("")).toEqual([
			{ value: String(ids["first-post"]), label: "First post" },
			{ value: String(ids["scheduled-post"]), label: "Coming soon" },
		]);
		expect(await search("COMING")).toEqual([{ value: String(ids["scheduled-post"]), label: "Coming soon" }]);
		expect(await search("незаб")).toEqual([]);
	});

	test("a host rule named link_shape or page_ref is refused, not silently replaced", async () => {
		for (const name of ["link_shape", "page_ref"]) {
			await expect(makeCms(null, { hostRules: { [name]: { check: () => true, message: "x" } } })).rejects.toThrow(`hostRules.${name}`);
		}
	});

	test("the link page search answers {search} and {value} under the admin", async () => {
		const { post, ids } = await panel();
		const endpoint = "/admin/link/page-search/select-options/page";
		const found = (await (await post(endpoint, { search: "work in" }, "/admin/link/page-search")).json()) as { options: unknown[] };
		expect(found.options).toEqual([{ value: String(ids.draft), label: "Work in progress" }]);
		const one = (await (await post(endpoint, { value: String(ids.about) }, "/admin/link/page-search")).json()) as { option: unknown };
		expect(one.option).toEqual({ value: String(ids.about), label: "About us" });
	});

	test("saving checks the link shape and the page references, then stores the targets", async () => {
		const { cms, withPromo, ids } = await panel();
		const { save, field } = await withPromo();
		expect(await save({ [field("cta")]: { type: "external", value: "javascript:alert(1)" }, [field("related")]: ["999"] })).toEqual([field("cta"), `${field("related")}.0`]);
		expect(await save({ [field("cta")]: { type: "page", page: "999" } })).toEqual([field("cta")]);
		expect(await save({ [field("cta")]: { type: "email", value: "a@b.test", label: { fr: "Écrire" } } })).toEqual([field("cta")]);
		expect(await save({ [field("related")]: ["1", "2", "3", "4"] })).toEqual([field("related")]);
		expect(await save({ [field("cta")]: { type: "email", value: "a@b.test" }, [field("related")]: [String(ids.about)] })).toEqual([]);

		expect(
			await save({
				[field("cta")]: { type: "phone", value: "+380 (44) 123-45-67", label: { en: "Call us" } },
				[field("related")]: [String(ids["scheduled-post"]), String(ids["first-post"])],
			}),
		).toEqual([]);
		const stored = (await cms.site().pages.find(ids.about as number))?.content?.[0] as { data: Record<string, unknown> };
		expect(stored.data).toEqual({
			cta: { type: "phone", value: "+380 (44) 123-45-67", label: { en: "Call us" } },
			related: [String(ids["scheduled-post"]), String(ids["first-post"])],
		});
		const result = await cms.loadPage(get("/about-us"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.render.elements.promo1?.props).toEqual({
			cta: { href: "tel:+380441234567", type: "phone", label: "Call us" },
			related: [{ id: String(ids["first-post"]), name: "First post", url: "/blog/first-post" }],
		});
	});

	test("a link field restricts its types and refuses to be translatable; a pages field too", () => {
		expect(() => linkField("cta").types(["page", "fax" as never])).toThrow(/unknown link type/);
		expect(() => linkField("cta").translatable()).toThrow(/not translatable/);
		expect(() => pagesField("related").translatable()).toThrow(/not translatable/);
		expect(linkField("cta").types(["external"]).ruleEntries()).toEqual(["nullable", "array", "link_shape:external"]);
		expect(linkField("cta").required().ruleEntries()).toEqual(["required", "array", "link_shape"]);
		expect(pagesField("related").exceptPage(7).ruleEntries()).toEqual(["nullable", "page_ref:except=7"]);
	});
});

const recipeType = definePageType({
	key: "recipe",
	schema: z.object({
		minutes: z.number().nullable(),
		vegan: z.boolean(),
		related: z.array(z.object({ id: z.string(), name: z.string(), url: z.string() })),
	}),
	form: (s) => [s.number("minutes").label("Minutes"), s.boolean("vegan").label("Vegan"), pagesField("related").of("article")],
});

/** A CMS with the recipe type over the demo database: createCms itself, so its page types are inferred. */
async function recipeCms() {
	const { db, ids } = await makeCms();
	const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", pageTypes: [recipeType] });
	return { cms, ids };
}

describe("typed page types (q06)", () => {
	test("a page type's zod fields type and check page.fields, narrowed by page.type", async () => {
		const { cms, ids } = await recipeCms();
		await cms.site().pages.create({
			type: "recipe",
			status: "published",
			name: { en: "Pasta" },
			fields: { minutes: "25", vegan: "1", related: [String(ids["first-post"]), String(ids.draft)] },
		});
		const result = await cms.loadPage(get("/pasta"));
		if (result.type !== "page") throw new Error(result.type);
		const { page } = result.props;
		if (page.type !== "recipe") throw new Error(page.type);
		expectTypeOf(page.fields.minutes).toEqualTypeOf<number | null>();
		expectTypeOf(page.fields.related).toEqualTypeOf<{ id: string; name: string; url: string }[]>();
		expect(page.fields).toEqual({ minutes: 25, vegan: true, related: [{ id: String(ids["first-post"]), name: "First post", url: "/blog/first-post" }] });

		const about = await cms.loadPage(get("/about-us"));
		if (about.type !== "page") throw new Error(about.type);
		expectTypeOf(about.props.page.type).toEqualTypeOf<"page" | "category" | "article" | "recipe">();
		if (about.props.page.type === "page") {
			// @ts-expect-error a built-in page type declares no fields
			void about.props.page.fields.minutes;
		}
	});

	test("resolved fields that fail the schema are dropped with a warning, or throw under strictBlocks", async () => {
		const { db } = await makeCms();
		const loose = definePageType({
			key: "event",
			schema: z.object({ starts: z.string() }),
			form: (s) => [s.text("starts")],
		});
		const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", pageTypes: [loose] });
		await cms.site().pages.create({ type: "event", status: "published", name: { en: "Launch" }, fields: {} });
		const result = await cms.loadPage(get("/launch"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.page.fields).toEqual({});
		const strict = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), url: "https://site.test", pageTypes: [loose], strictBlocks: true });
		await expect(strict.loadPage(get("/launch"))).rejects.toThrow(/event/);
	});

	test("createCms refuses a page type whose form fields and schema keys differ", async () => {
		const { db } = await makeCms(null);
		const blocks = implementBlocks(cmsBlocks, cmsBlockImpls);
		const mismatched = definePageType({
			key: "recipe",
			schema: z.object({ minutes: z.number().nullable(), servings: z.number() }),
			form: (s) => [s.number("minutes"), s.text("note")],
		});
		expect(() => createCms({ db, blocks, pageTypes: [mismatched] })).toThrow(
			"Page type [recipe]: its form() fields do not match its schema: no form field for servings; no schema key for note.",
		);
		expect(() => createCms({ db, blocks, pageTypes: [{ key: "bare", schema: z.object({ x: z.string() }) }] })).toThrow(/no form field for x/);
		expect(() => createCms({ db, blocks, pageTypes: [{ key: "computed", schema: z.object({ x: z.string() }), resolveFields: () => ({ x: "1" }) }] })).not.toThrow();
	});

	test("PageProps and a bare Cms keep untyped fields; a typed Cms is still a Cms", async () => {
		const { cms } = await recipeCms();
		const bare: Cms = cms;
		expectTypeOf<PageProps["page"]["fields"]>().toEqualTypeOf<Record<string, Json>>();
		expectTypeOf<Extract<PageProps<[typeof recipeType]>["page"], { type: "recipe" }>["fields"]["vegan"]>().toEqualTypeOf<boolean>();
		expect(bare.pageTypes.has("recipe")).toBe(true);
	});
});

describe("page links resolve in a batch", () => {
	test("a page with many page links looks the targets up once, not twice per link", async () => {
		const { cms, ids } = await makeCms(undefined, { blocks: promoBlocks() });
		const link = (page: unknown) => ({ cta: { type: "page", page: String(page), label: { en: "Go" } } });
		const content = [ids.about, ids.blog, ids["first-post"], ids.draft, 9999].map((page, sort) => ({ id: `p${sort}`, kind: "Promo", active: true, sort, data: link(page) }));
		await cms.site().pages.update(ids.home as number, { content });
		const proto = Object.getPrototypeOf(cms.site().pages) as { find: (...args: unknown[]) => unknown };
		const find = proto.find;
		let finds = 0;
		proto.find = function (this: unknown, ...args: unknown[]) {
			finds++;
			return find.apply(this, args);
		};
		try {
			const result = await cms.loadPage(get("/"));
			if (result.type !== "page") throw new Error(result.type);
			const hrefs = Object.values(result.props.render.elements)
				.filter((element) => element.type === "Promo")
				.map((element) => (element.props.cta as { href: string } | null)?.href ?? null);
			// The draft and the missing page link nowhere.
			expect(hrefs).toEqual(["/about-us", "/blog", "/blog/first-post", null, null]);
			expect(finds).toBeLessThan(3);
		} finally {
			proto.find = find;
		}
	});
});

describe("block JSON-LD", () => {
	test("a block's schema node gets the schema.org @context when it names none", async () => {
		const blocks = implementBlocks(promoCatalog, {
			...cmsBlockImpls,
			Promo: { form: () => [linkField("cta"), pagesField("related")], toSchema: () => ({ "@type": "FAQPage", mainEntity: [] }) },
		});
		const { cms, ids } = await makeCms(undefined, { blocks });
		await cms.site().pages.update(ids.about as number, { content: [{ id: "faq1", kind: "Promo", active: true, sort: 0, data: {} }] });
		const result = await cms.loadPage(get("/about-us"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.seo.jsonLd).toContainEqual({ "@context": "https://schema.org", "@type": "FAQPage", mainEntity: [] });
	});
});
