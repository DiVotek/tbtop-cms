import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { type CmsConfig, RedirectError } from "../src";
import { get, makeCms } from "./support";

type Row = { id: string; kind: string; active?: boolean; sort?: number; data?: Record<string, unknown> };

async function panel(config: Partial<CmsConfig> = {}, user: () => unknown = () => ({ id: 1, name: "Admin" })) {
	const made = await makeCms(undefined, config);
	const { cms, db } = made;
	const admin = createAdmin({
		prefix: "/admin",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: kyselyRules(db),
		user,
		context: cms.admin.context,
	});
	let cookie = "";
	const visit = async (url: string, init: RequestInit = {}) => {
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
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const record = async (url: string, form: string) => ((await page(url)).props.data as Record<string, Record<string, unknown>>)[form] ?? {};
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const rows = async (id: number) => ((await cms.site().pages.find(id))?.content ?? []) as Row[];
	const setRows = async (id: number, content: Row[]) => {
		await db.updateTable("tbtop_pages").set({ content: JSON.stringify(content) }).where("id", "=", id).execute();
	};
	return { ...made, admin, visit, page, record, post, rows, setRows };
}

/** The home page's Hero props as the site resolves them. */
async function heroProps(cms: Awaited<ReturnType<typeof makeCms>>["cms"]): Promise<Record<string, unknown>> {
	const result = await cms.loadPage(get("/"));
	if (result.type !== "page") {
		throw new Error(result.type);
	}
	const elements = (result.props.render as { elements: Record<string, { type: string; props: Record<string, unknown> }> }).elements;
	const hero = Object.values(elements).find((element) => element.type === "Hero");
	if (hero === undefined) {
		throw new Error("no Hero");
	}
	return hero.props;
}

describe("redirects.allowedExternalHosts (q11)", () => {
	test("an external target on a listed host is stored and followed, matched case-insensitively", async () => {
		const { cms } = await makeCms(undefined, { redirects: { allowedExternalHosts: ["Partner.Example"] } });
		await cms.site().redirects.create({ fromPath: "promo", toPath: "https://partner.EXAMPLE/deal" });
		const result = await cms.loadPage(get("/promo"));
		expect(result).toMatchObject({ type: "redirect", status: 301, location: "https://partner.EXAMPLE/deal" });
	});

	test("other hosts and non-http schemes stay rejected", async () => {
		const { cms } = await makeCms(undefined, { redirects: { allowedExternalHosts: ["partner.example"] } });
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "https://evil.example/x" })).rejects.toThrow(
			new RedirectError("External redirect host is not allowlisted."),
		);
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "//partner.example/x" })).rejects.toThrow(
			new RedirectError("External redirect target must be a valid http(s) URL with a host."),
		);
	});

	test("without the key every external target is rejected, as before", async () => {
		const { cms } = await makeCms();
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "https://partner.example/x" })).rejects.toThrow(
			new RedirectError("External redirect host is not allowlisted."),
		);
	});
});

describe("Hero ctaHref allows only safe links (q04)", () => {
	const withHref = async (href: string) => {
		const made = await panel();
		const home = made.ids.home as number;
		const hero = (await made.rows(home)).find((row) => row.kind === "Hero") as Row;
		await made.setRows(home, [{ ...hero, data: { ...hero.data, ctaHref: href } }]);
		return { ...made, home, hero };
	};

	for (const href of ["javascript:alert(1)", " JavaScript:alert(1)", "data:text/html,<script>1</script>", "//evil.example/x", "/\\evil.example"]) {
		test(`a stored ${JSON.stringify(href)} resolves to no CTA`, async () => {
			const { cms } = await withHref(href);
			expect((await heroProps(cms)).cta).toBeNull();
		});
	}

	for (const href of ["/blog", "#contact", "https://partner.example/deal", "mailto:hi@site.test", "tel:+380441234567", "tg://resolve?domain=site"]) {
		test(`a stored ${JSON.stringify(href)} keeps its CTA`, async () => {
			const { cms } = await withHref(href);
			expect((await heroProps(cms)).cta).toEqual({ label: "Read the blog", href });
		});
	}

	test("the page editor refuses a javascript: CTA link and keeps the stored one", async () => {
		const { record, post, page, rows, home, hero } = await withHref("/blog");
		const url = `/admin/pages/${home}/edit`;
		await post(`${url}/forms/pageEditor`, { ...(await record(url, "pageEditor")), [`block_${hero.id}_ctaHref`]: "javascript:alert(1)" }, url);
		expect(Object.keys((await page(url)).props.errors as Record<string, unknown>)).toContain(`block_${hero.id}_ctaHref`);
		expect((await rows(home)).find((row) => row.id === hero.id)?.data?.ctaHref).toBe("/blog");
	});

	test("the page editor saves a safe CTA link", async () => {
		const { record, post, rows, home, hero } = await withHref("/blog");
		const url = `/admin/pages/${home}/edit`;
		const res = await post(`${url}/forms/pageEditor`, { ...(await record(url, "pageEditor")), [`block_${hero.id}_ctaHref`]: "HTTPS://partner.example/deal" }, url);
		expect(res.status).toBe(303);
		expect((await rows(home)).find((row) => row.id === hero.id)?.data?.ctaHref).toBe("HTTPS://partner.example/deal");
	});
});
