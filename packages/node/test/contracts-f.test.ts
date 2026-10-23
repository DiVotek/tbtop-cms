import { describe, expect, test } from "bun:test";
import type { HostRule } from "@tbtop/admin";
import { kyselyRules } from "@tbtop/admin/kysely";
import { cmsBlockImpls, cmsBlocks, type CmsConfig, demoFixture, ensureErrorPages, type ErrorPageProps, implementBlocks, type LoadResult } from "../src";
import { seoTags } from "../src/react";
import { get, makeCms } from "./support";

const notFound = (result: LoadResult): ErrorPageProps => {
	if (result.type !== "notFound") {
		throw new TypeError(`expected notFound, got ${result.type}`);
	}
	return result.props;
};

describe("error pages (q09)", () => {
	test("without an error page a 404 carries fallback props: the package heading, no blocks, noindex", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/nope"));
		expect(result).toMatchObject({ type: "notFound", status: 404, locale: "en", path: "nope" });
		const props = notFound(result);
		expect(props).toMatchObject({ status: 404, locale: "en", path: "nope", breadcrumbs: [], isPreview: false });
		expect(props.page).toMatchObject({ id: null, type: "page", heading: "Page not found" });
		expect(props.render.elements).toEqual({ $root: expect.anything() });
		expect(props.seo).toMatchObject({ title: "Page not found", noindex: true, canonical: null, ogUrl: null, jsonLd: [], siteName: "tbtop demo" });
		expect(props.cms.locale).toBe("en");
		expect(notFound(await cms.loadPage(get("/uk/nope"))).page.heading).toBe("Сторінку не знайдено");
	});

	test("the live 4xx page the editor keeps renders on a 404, with no URL, canonical or JSON-LD of its own", async () => {
		const { cms, db } = await makeCms();
		expect(await ensureErrorPages(cms)).toEqual(["4xx", "5xx"]);
		expect(await ensureErrorPages(cms)).toEqual([]);
		const site = cms.site();
		const row = await site.pages.query().selectAll().where("error_class", "=", "4xx").executeTakeFirstOrThrow();
		// A Postgres bigint comes back as a string.
		const page = { ...row, id: Number(row.id) };
		await site.pages.update(page.id, {
			heading: { en: "Lost?", uk: "Загубились?" },
			content: [{ id: "hero1", kind: "Hero", active: true, sort: 0, data: { title: { en: "Try the blog" }, ctaHref: "/blog" } }],
		});

		const props = notFound(await cms.loadPage(get("/missing/deep")));
		expect(props.page).toMatchObject({ id: page.id, heading: "Lost?" });
		expect(props.render.elements.hero1).toMatchObject({ type: "Hero", props: { title: "Try the blog" } });
		expect(props.seo).toMatchObject({ noindex: true, canonical: null, ogUrl: null, jsonLd: [] });
		expect(props.seo.title).toContain("Page not found");
		expect(props.path).toBe("missing/deep");
		expect(notFound(await cms.loadPage(get("/uk/x"))).page.heading).toBe("Загубились?");

		const tags = seoTags(props.seo);
		expect(tags.some((tag) => tag.tag === "link" && tag.attrs?.rel === "canonical")).toBe(false);
		expect(tags.some((tag) => tag.tag === "meta" && tag.attrs?.property === "og:url")).toBe(false);
		expect(tags).toContainEqual({ tag: "meta", attrs: { name: "robots", content: "noindex" } });

		// Never served at a URL, and one that is not live falls back.
		expect((await cms.loadPage(get("/page-not-found"))).type).toBe("notFound");
		await db.updateTable("tbtop_pages").set({ status: "draft" }).where("id", "=", page.id).execute();
		expect(notFound(await cms.loadPage(get("/nope"))).page.id).toBeNull();
	});

	test("cms.errorPage gives a host's 500 handler the 5xx page in the request's locale", async () => {
		const { cms } = await makeCms();
		await ensureErrorPages(cms);
		const props = await cms.errorPage(get("/uk/blog?x=1"), 500);
		expect(props).toMatchObject({ status: 500, locale: "uk", path: "blog", seo: { noindex: true, canonical: null, jsonLd: [] } });
		expect(props.page.heading).toBe("Something went wrong");
		expect(props.page.id).not.toBeNull();
		expect((await cms.errorPage(get("/x"), 503)).page.id).toBe(props.page.id);
		expect((await cms.errorPage(get("/x"), 404)).page.heading).toBe("Page not found");
	});

	test("a 5xx never throws: with the database gone it answers props built without it", async () => {
		const { cms, db } = await makeCms();
		await db.destroy();
		const props = await cms.errorPage(get("/blog"), 500);
		expect(props).toMatchObject({ status: 500, path: "blog", page: { id: null, heading: "Something went wrong" }, seo: { noindex: true } });
		expect(props.cms.layout).toEqual({ header: [], footer: [] });
		await expect(cms.errorPage(get("/blog"), 404)).rejects.toThrow();
	});

	test("a fixture page with errorClass becomes that class's error page", async () => {
		const { cms } = await makeCms({
			...demoFixture,
			pages: [...(demoFixture.pages ?? []), { errorClass: "4xx", name: { en: "Oops" }, heading: { en: "Nothing here" } }],
		});
		expect(notFound(await cms.loadPage(get("/nope"))).page.heading).toBe("Nothing here");
		expect(await ensureErrorPages(cms)).toEqual(["5xx"]);
	});
});

describe("form validation host rules (q13)", () => {
	type Made = Awaited<ReturnType<typeof makeCms>>;
	const late: { db?: Made["db"] } = {};
	/** kyselyRules over the test's own database, which exists only once makeCms ran. */
	const lateKysely = (name: string): HostRule => ({
		message: "The :attribute has already been taken.",
		check: (value, params, key) => {
			const rule = late.db === undefined ? undefined : kyselyRules(late.db)[name];
			if (rule === undefined) {
				throw new Error(`no ${name} rule`);
			}
			return rule.check(value, params, key);
		},
	});
	const even: HostRule = { message: "The :attribute must be even.", check: (value) => Number(value) % 2 === 0 };

	async function site(rules: Record<string, string>, hostRules?: CmsConfig["hostRules"]) {
		const made = await makeCms(undefined, {
			blocks: implementBlocks(cmsBlocks, { ...cmsBlockImpls, ContactForm: { ...cmsBlockImpls.ContactForm, submit: { rules: () => rules } } }),
			...(hostRules === undefined ? {} : { hostRules }),
		});
		late.db = made.db;
		const home = await made.cms.loadPage(get("/"));
		if (home.type !== "page") {
			throw new Error("no home");
		}
		const form = Object.values(home.props.render.elements).find((element) => element.type === "ContactForm");
		const action = (form?.props as { action: string }).action;
		const submit = async (body: Record<string, unknown>) => {
			const res = await made.cms.handle(new Request(new URL(action, "https://site.test"), { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(body) }));
			if (res === null) {
				throw new TypeError("the submit fell through");
			}
			return { status: res.status, body: (await res.json()) as { ok: boolean; errors?: Record<string, string[]> } };
		};
		return { ...made, submit };
	}

	test("a block's rules run with the host's rules: unique/exists from kyselyRules and the host's own", async () => {
		const { submit, db } = await site({ locale: "required|unique:tbtop_languages,locale", count: "required|even" }, { unique: lateKysely("unique"), even });
		expect(await submit({ locale: "en", count: 3 })).toMatchObject({ status: 422, body: { ok: false, errors: { locale: [expect.any(String)], count: ["The count must be even."] } } });
		expect(await submit({ locale: "fr", count: 4 })).toEqual({ status: 200, body: { ok: true } });
		expect(await db.selectFrom("tbtop_form_submissions").select("id").execute()).toHaveLength(1);
	});

	test("the CMS's own rules (page_ref) validate a form too, and cms.admin.hostRules carries the host's", async () => {
		const { submit, cms, ids } = await site({ page: "required|page_ref" }, { even });
		expect((await submit({ page: String(ids.about) })).status).toBe(200);
		expect((await submit({ page: "999999" })).status).toBe(422);
		expect(await cms.admin.hostRules.even?.check("2", [], "n")).toBe(true);
	});

	test("an unknown rule answers failed with a form error instead of an unhandled 500", async () => {
		const { submit, db } = await site({ email: "required|unique:users,email" });
		const errors: unknown[] = [];
		const original = console.error;
		console.error = (...args: unknown[]) => void errors.push(args);
		try {
			const res = await submit({ email: "ada@example.test" });
			expect(res).toEqual({ status: 500, body: { ok: false, errors: { form: [expect.any(String)] } } });
		} finally {
			console.error = original;
		}
		expect(errors).toHaveLength(1);
		expect(await db.selectFrom("tbtop_form_submissions").select("id").execute()).toHaveLength(0);
	});
});
