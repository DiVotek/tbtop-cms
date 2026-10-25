import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { type CmsAbility, type CmsConfig, cmsBlocks, previewLink, RedirectError } from "../src";
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

/** The first wire node named `name` anywhere in `tree`. */
function findNode(tree: unknown, name: string): unknown {
	if (Array.isArray(tree)) {
		for (const item of tree) {
			const found = findNode(item, name);
			if (found !== null) return found;
		}
		return null;
	}
	if (tree === null || typeof tree !== "object") {
		return null;
	}
	if ((tree as { name?: unknown }).name === name) {
		return tree;
	}
	return findNode(Object.values(tree), name);
}

/** The labels in the panel's sidebar. */
const navLabels = (props: Record<string, unknown>): string[] =>
	((props.tbtop as { nav: { items: { label: string }[] }[] }).nav ?? []).flatMap((group) => group.items.map((item) => item.label));

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

	/** HeroBlockTest's script links, plus the browser-normalized spellings SafeHref catches. */
	const unsafe = [
		"javascript:alert(1)",
		"JavaScript:alert(1)",
		"java\tscript:alert(1)",
		"data:text/html,x",
		"vbscript:x",
		"\u0001javascript:alert(1)",
		"jav\na\rscript:alert(1)",
		"javascript\t:alert(1)",
		"tg:// x",
		"httpx://site.test",
	];
	/** HeroBlockTest's relative, web, mail, phone and messenger links: a value without a scheme is relative. */
	const safe = [
		"/contact",
		"contact#form",
		"#form",
		"?ref=hero",
		"https://example.test/a:b",
		"mailto:a@example.test",
		"tel:+380441234567",
		"tg://resolve?domain=tbtop",
		"blog",
		"HTTPS://partner.example/deal",
		"ht\ttps://partner.example",
		"viber:chat",
		"/a:b",
		"//cdn.example/x",
	];

	for (const href of unsafe) {
		test(`a stored ${JSON.stringify(href)} resolves to no CTA`, async () => {
			const { cms } = await withHref(href);
			expect((await heroProps(cms)).cta).toBeNull();
		});
	}

	for (const href of safe) {
		test(`a stored ${JSON.stringify(href)} keeps its CTA as written`, async () => {
			const { cms } = await withHref(href);
			expect((await heroProps(cms)).cta).toEqual({ label: "Read the blog", href });
		});
	}

	test("the page editor refuses exactly the links the site drops", async () => {
		const { record, post, page, rows, home, hero } = await withHref("/blog");
		const url = `/admin/pages/${home}/edit`;
		const field = `block_${hero.id}_ctaHref`;
		const verdicts: [string, boolean][] = [];
		for (const href of [...unsafe, ...safe]) {
			await post(`${url}/forms/pageEditor`, { ...(await record(url, "pageEditor")), [field]: href }, url);
			const refused = Object.keys((await page(url)).props.errors as Record<string, unknown>).includes(field);
			verdicts.push([href, !refused]);
		}
		expect(verdicts).toEqual([...unsafe.map((href) => [href, false] as [string, boolean]), ...safe.map((href) => [href, true] as [string, boolean])]);
		expect((await rows(home)).find((row) => row.id === hero.id)?.data?.ctaHref).toBe(safe.at(-1));
	});

	test("the catalog's Hero schema refuses an unsafe cta href from any source of props", () => {
		const props = (href: string) => ({ title: "T", subtitle: null, image: null, cta: { label: "Go", href } });
		for (const href of unsafe) {
			expect([href, cmsBlocks.Hero.props.safeParse(props(href)).success]).toEqual([href, false]);
		}
		expect(cmsBlocks.Hero.props.safeParse(props("/contact#form")).success).toBe(true);
	});
});

describe("admin section abilities (q02)", () => {
	const editor = { id: 2, name: "Editor", role: "editor" };
	/** An editor holds pages and the inbox only. */
	const editorAuth: CmsConfig["authorize"] = async (user, ability) =>
		(user as { role?: string } | null)?.role !== "editor" || ["tbtop-cms.pages", "tbtop-cms.submissions"].includes(ability);

	test("a denied section leaves the nav and answers 403 to its pages and actions", async () => {
		const asked: [unknown, CmsAbility][] = [];
		const { page, visit, post } = await panel(
			{
				authorize: async (user, ability) => {
					asked.push([user, ability]);
					return (await editorAuth?.(user, ability)) === true;
				},
			},
			() => editor,
		);
		expect(navLabels((await page("/admin/pages")).props)).toEqual(["Pages"]);
		for (const url of ["/admin/redirects", "/admin/layout", "/admin/blocks", "/admin/site-health", "/admin/audit", "/admin/layout/header", "/admin/shared-blocks", "/admin/languages", "/admin/settings/site", "/admin/settings/seo", "/admin/notification-settings"]) {
			expect([url, (await visit(url)).status]).toEqual([url, 403]);
		}
		expect((await post("/admin/settings/site/forms/siteSettings", { site_name: "Hijacked" }, "/admin/settings/site")).status).toBe(403);
		expect((await post("/admin/redirects/actions/redirectCreate", { payload: { form: {} } }, "/admin/redirects")).status).toBe(403);
		expect(asked.some(([user, ability]) => user === editor && ability === "tbtop-cms.settings")).toBe(true);
	});

	test("the audit log needs tbtop-cms.audit, not settings", async () => {
		const settingsOnly: CmsConfig["authorize"] = async (_user, ability) => ability === "tbtop-cms.settings";
		const auditOnly: CmsConfig["authorize"] = async (_user, ability) => ability === "tbtop-cms.audit";
		expect((await (await panel({ authorize: settingsOnly }, () => editor)).visit("/admin/audit")).status).toBe(403);
		expect((await (await panel({ authorize: auditOnly }, () => editor)).visit("/admin/audit")).status).toBe(200);
	});

	test("the link page search needs a section with link fields (pages or design)", async () => {
		const inboxOnly: CmsConfig["authorize"] = async (_user, ability) => ability === "tbtop-cms.submissions";
		const designOnly: CmsConfig["authorize"] = async (_user, ability) => ability === "tbtop-cms.design";
		const search = async (authorize: CmsConfig["authorize"]) =>
			(await (await panel({ authorize }, () => editor)).post("/admin/link/page-search/select-options/page", { search: "" }, "/admin/link/page-search")).status;
		expect(await search(inboxOnly)).toBe(403);
		expect(await search(designOnly)).toBe(200);
		expect(await search(editorAuth)).toBe(200);
	});

	test("an allowed admin keeps every section, and without authorize nothing changes", async () => {
		for (const config of [{ authorize: editorAuth }, {}]) {
			const { page } = await panel(config, () => ({ id: 1, name: "Admin", role: "admin" }));
			const nav = navLabels((await page("/admin/pages")).props);
			for (const label of ["Pages", "Redirects", "Layouts", "Blocks", "Shared blocks", "Languages", "Site settings", "SEO settings", "Notifications", "Audit log", "Site health"]) {
				expect(nav).toContain(label);
			}
			expect((await page("/admin/settings/site")).component).toBe("admin/page");
		}
	});

	test("without the design ability the page editor offers no Share and no shared blocks to add", async () => {
		const { page, ids } = await panel({ authorize: editorAuth }, () => editor);
		const url = `/admin/pages/${ids.about}/edit`;
		const editorProps = JSON.stringify((await page(url)).props);
		expect(editorProps).toContain("blockAdd");
		expect(editorProps).not.toContain("blockShare_");
		expect(editorProps).not.toContain("blockAddShared_");
		const admin = await panel({ authorize: editorAuth });
		expect(JSON.stringify((await admin.page(`/admin/pages/${admin.ids.about}/edit`)).props)).toContain("blockAddShared_");
	});
});

describe("signed preview links (q02)", () => {
	const secret = "test-preview-secret";
	/** The editor's preview link for a locale, read off the page editor. */
	const linkIn = (props: unknown, locale: string): string | null => {
		const node = findNode(props, `pagePreviewLink_${locale}`);
		return node === null ? null : (/"(https:\/\/site\.test\/[^"]*signature=[0-9a-f]+)"/.exec(JSON.stringify(node))?.[1] ?? null);
	};

	test("the editor of a draft offers a signed link that previews it, the plain URL stays 404", async () => {
		const { cms, page, ids } = await panel({ preview: { secret } });
		const link = linkIn((await page(`/admin/pages/${ids.draft}/edit`)).props, "en");
		expect(link).not.toBeNull();
		const url = new URL(link as string);
		expect(url.pathname).toBe("/work-in-progress");
		const expires = Number(url.searchParams.get("expires")) * 1000;
		expect(Math.abs(expires - (Date.now() + 72 * 3600 * 1000))).toBeLessThan(60_000);
		const result = await cms.loadPage(new Request(link as string));
		expect(result.type).toBe("page");
		expect(result.type === "page" && result.props.isPreview).toBe(true);
		expect((await cms.loadPage(get("/work-in-progress"))).type).toBe("notFound");
		const uk = linkIn((await page(`/admin/pages/${ids.draft}/edit`)).props, "uk") as string;
		expect(new URL(uk).pathname).toBe("/uk/work-in-progress");
		expect((await cms.loadPage(new Request(uk))).type).toBe("page");
	});

	test("a tampered, re-pointed or expired signature does not preview", async () => {
		const { cms } = await makeCms(undefined, { preview: { secret, ttlHours: 1 } });
		const site = cms.site();
		const link = new URL((await previewLink(site, "work-in-progress", "en")) as string);
		const tampered = new URL(link);
		const signature = link.searchParams.get("signature") ?? "";
		tampered.searchParams.set("signature", `${signature.slice(0, -1)}${signature.endsWith("0") ? "1" : "0"}`);
		const repointed = new URL(link);
		repointed.pathname = "/blog/scheduled-post";
		const extended = new URL(link);
		extended.searchParams.set("expires", String(Number(link.searchParams.get("expires")) + 3600));
		const expired = (await previewLink(site, "work-in-progress", "en", Date.now() - 2 * 3600 * 1000)) as string;
		for (const url of [tampered.toString(), repointed.toString(), extended.toString(), expired]) {
			expect([url, (await cms.loadPage(new Request(url))).type]).toEqual([url, "notFound"]);
		}
		expect((await cms.loadPage(new Request(link.toString()))).type).toBe("page");
	});

	test("a link signed with another secret, or with no secret configured, does not preview", async () => {
		const other = await makeCms(undefined, { preview: { secret: "another-secret" } });
		const link = (await previewLink(other.cms.site(), "work-in-progress", "en")) as string;
		const { cms } = await makeCms(undefined, { preview: { secret } });
		expect((await cms.loadPage(new Request(link))).type).toBe("notFound");
		const bare = await makeCms();
		expect((await bare.cms.loadPage(new Request(link))).type).toBe("notFound");
		expect(await previewLink(bare.cms.site(), "work-in-progress", "en")).toBeNull();
	});

	test("a live page, or a config without a secret, gets no preview link in the editor", async () => {
		const live = await panel({ preview: { secret } });
		expect(linkIn((await live.page(`/admin/pages/${live.ids.about}/edit`)).props, "en")).toBeNull();
		const bare = await panel();
		expect(JSON.stringify((await bare.page(`/admin/pages/${bare.ids.draft}/edit`)).props)).not.toContain("pagePreviewLink_");
	});

	test("canPreview still opens drafts on its own", async () => {
		const { cms } = await makeCms(undefined, { preview: { secret }, canPreview: (request) => request.headers.get("x-editor") === "1" });
		expect((await cms.loadPage(get("/work-in-progress", { headers: { "x-editor": "1" } }))).type).toBe("page");
	});
});
