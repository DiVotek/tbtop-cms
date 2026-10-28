import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { RedirectError } from "../src";
import { slugify } from "../src/support/slug";
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
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	/** The notification or halt a form answered with, from its JSON body or the flash on the next visit. */
	const notice = async (res: Response, url: string): Promise<string> =>
		res.headers.get("content-type")?.includes("json") ? JSON.stringify(await res.json()) : JSON.stringify(await page(url));
	return { ...made, visit, post, page, notice };
}

describe("redirect validation (R2-content-1)", () => {
	const rejects = async (input: Parameters<ReturnType<Awaited<ReturnType<typeof makeCms>>["cms"]["site"]>["redirects"]["create"]>[0], message: string) => {
		const { cms } = await makeCms();
		await expect(cms.site().redirects.create(input)).rejects.toThrow(new RedirectError(message));
	};

	test("external targets are rejected: no host is allowlisted", async () => {
		await rejects({ fromPath: "promo", toPath: "https://evil.example/x" }, "External redirect host is not allowlisted.");
		await rejects({ fromPath: "promo", toPath: "/\\evil.example" }, "External redirect target must be a valid http(s) URL with a host.");
	});

	test("an empty or external from, a blank target and a bad status are rejected", async () => {
		await rejects({ fromPath: "/", toPath: "about" }, "From and to paths are required.");
		await rejects({ fromPath: "promo", toPath: "   " }, "From and to paths are required.");
		await rejects({ fromPath: "https://x.example/a", toPath: "about" }, "From path must be a local path, not an external URL.");
		await rejects({ fromPath: "promo", toPath: "about", statusCode: 307 }, "Status code must be 301 or 302.");
	});

	test("a from that is a live page path is rejected", async () => {
		await rejects({ fromPath: "about-us", toPath: "blog" }, "A page already exists at this path.");
	});

	test("duplicates are rejected per locale, NULL included", async () => {
		const { cms } = await makeCms();
		const redirects = cms.site().redirects;
		await redirects.create({ fromPath: "a", toPath: "b" });
		await expect(redirects.create({ fromPath: "a", toPath: "c" })).rejects.toThrow("A redirect already exists for this path and locale.");
		await redirects.create({ fromPath: "a", toPath: "c", locale: "en" });
		await expect(redirects.create({ fromPath: "/a/", toPath: "d", locale: "en" })).rejects.toThrow("A redirect already exists for this path and locale.");
	});

	test("loops are rejected, also through an any-locale rule", async () => {
		const { cms } = await makeCms();
		const redirects = cms.site().redirects;
		await redirects.create({ fromPath: "a", toPath: "b", locale: "en" });
		await expect(redirects.create({ fromPath: "b", toPath: "a", locale: "en" })).rejects.toThrow("This redirect would create a loop.");
		await expect(redirects.create({ fromPath: "b", toPath: "a" })).rejects.toThrow("This redirect would create a loop.");
		await expect(redirects.create({ fromPath: "x", toPath: "x" })).rejects.toThrow("This redirect would create a loop.");
	});

	test("a valid rule is stored trimmed and resolves", async () => {
		const { cms } = await makeCms();
		await cms.site().redirects.create({ fromPath: "/promo/", toPath: "  /about/  " });
		const result = await cms.loadPage(get("/promo"));
		expect(result).toMatchObject({ type: "redirect", status: 301 });
	});

	test("the admin form halts the modal with the message instead of storing or crashing", async () => {
		const { post, db, notice } = await panel();
		const url = "/admin/redirects";
		const submit = (form: Record<string, unknown>) => post(`${url}/forms/redirectCreate`, form, url);
		const before = await db.selectFrom("tbtop_redirects").selectAll().execute();
		const open = await submit({ from_path: "promo", to_path: "https://evil.example/x", status_code: "301" });
		expect(await notice(open, url)).toContain("not allowlisted");
		await submit({ from_path: "a", to_path: "b", locale: "en", status_code: "301" });
		const duplicate = await submit({ from_path: "a", to_path: "c", locale: "en", status_code: "301" });
		expect(duplicate.status).toBeLessThan(500);
		expect(await notice(duplicate, url)).toContain("already exists");
		expect((await db.selectFrom("tbtop_redirects").selectAll().execute()).length).toBe(before.length + 1);
	});
});

describe("make default (R2-content-3)", () => {
	test("making a language that is not live the default backfills its slugs, so the site stays up", async () => {
		const { cms, post, db, ids } = await panel();
		const de = await cms.site().languages.create({ name: "Deutsch", locale: "de", isAdminActive: true, isFrontendActive: false });
		const url = "/admin/languages";
		const res = await post(`${url}/actions/makeDefault`, { payload: { row: { id: de.id, locale: "de" } } }, url);
		expect(res.status).toBeLessThan(400);
		expect((await cms.site().languages.defaultLocale())).toBe("de");
		const slugs = await db.selectFrom("tbtop_page_slugs").select("path").where("locale", "=", "de").execute();
		expect(slugs.length).toBeGreaterThan(0);
		expect((await cms.loadPage(get("/"))).type).not.toBe("notFound");
		const about = await db.selectFrom("tbtop_page_slugs").select("path").where("locale", "=", "de").where("page_id", "=", ids.about as number).executeTakeFirstOrThrow();
		expect((await cms.loadPage(get(`/${about.path}`))).type).toBe("page");
	});
});

describe("slugs match Str::slug (R2-content-5)", () => {
	/** Str::slug outputs from illuminate/support 12 (voku/portable-ascii 2.1.1). */
	const php: [string, string][] = [
		["Щастя їжак Єва хліб", "shhastia-yizak-jeva-xlib"],
		["Київ, вул. Хрещатик 1/2", "kiyiv-vul-xreshhatik-12"],
		["Ж Х Ц Ч Ш Щ", "z-x-c-c-s-shh"],
		["Ёлка ЪЫЬ Эх юла", "elka-iey-ex-iula"],
		["Ґанок цукор", "ganok-cukor"],
		["Ελληνικά", "ellinika"],
		["مرحبا", "mrhba"],
		["Ğüşçö ıİ", "gusco-ii"],
		["ﬁ ½ ™ №5", "fi-5"],
		["Hello World", "hello-world"],
		["user@example.com", "user-at-examplecom"],
		["snake_case and-dash", "snake-case-and-dash"],
		["Düsseldorf Straße", "dusseldorf-strasse"],
		["Æsir øl œuvre łódź đak", "aesir-ol-oeuvre-lodz-dak"],
		["  --Leading & trailing!!--  ", "leading-trailing"],
		["Про нас", "pro-nas"],
		["Головна сторінка нашого сайту про все на світі та ще трохи більше тексту", "golovna-storinka-nasogo-saitu-pro-vse-na-sviti-ta-shhe-troxi-bilse-tekstu"],
		["Aι short", "ai-short"],
		["Αι Greek", "ai-greek"],
		["Ünïcödé Ēxtrâ Lõng Tîtlé Thät Gœs Pàst Sixty Four Bytes Of UTF-8 Ïnpüt Aι", "unicode-extra-long-title-that-goes-past-sixty-four-bytes-of-utf-8-input-ai"],
		["Привет, мир! Как дела?", "privet-mir-kak-dela"],
		["中文 标题", ""],
		["日本語", ""],
		["한국어", ""],
		["עברית", ""],
		["Tiếng Việt có dấu", "tieng-viet-co-dau"],
		["ქართული", "qartuli"],
		["Հայերեն", "hayeren"],
		["\tTab\nnew", "tab-new"],
		["A̧ combining", "a-combining"],
		["€ 100 £ 5", "eur-100-ps-5"],
		["Ⅻ ①", ""],
		["---", ""],
		["Ω≈ç√∫", "oc"],
		["Șțăîâ", "staia"],
		["Ꜳ", "aa"],
	];

	test("slugify gives Laravel's output, Cyrillic, Greek and Arabic included", () => {
		expect(php.map(([input]) => [input, slugify(input)])).toEqual(php);
	});

	test("a new page's slug is derived like PHP's; a stored slug is kept on rename", async () => {
		const { cms } = await makeCms();
		const site = cms.site();
		const page = await site.pages.create({ type: "page", name: { en: "Ελληνικά", uk: "Київ, вул. Хрещатик 1/2" } });
		expect(await site.pages.slugs.allFor(page.id)).toMatchObject({ en: "ellinika", uk: "kiyiv-vul-xreshhatik-12" });
		await site.pages.update(page.id, { name: { en: "Renamed", uk: "Інша назва" } });
		expect(await site.pages.slugs.allFor(page.id)).toMatchObject({ en: "ellinika", uk: "kiyiv-vul-xreshhatik-12" });
	});
});

describe("SEO settings (R2-content-11)", () => {
	const url = "/admin/settings/seo";
	const save = async (made: Awaited<ReturnType<typeof panel>>, form: Record<string, unknown>) =>
		made.notice(await made.post(`${url}/forms/settings_seo`, { seo_identity_type: "none", ...form }, url), url);

	test("every page type gets per-type title and description templates the resolver reads", async () => {
		const made = await panel();
		const page = JSON.stringify(await made.page(url));
		expect(page).toContain("seo_type_article_title");
		expect(page).toContain("seo_type_category_description");
		await save(made, { seo_type_article_title: { en: "{title} — Journal", uk: "" } });
		expect(await made.cms.site().settings.get("seo.type_templates.article.title")).toMatchObject({ en: "{title} — Journal" });
		const result = await made.cms.loadPage(get("/blog/first-post"));
		expect(JSON.stringify(result)).toContain("First post — Journal");
	});

	test("a robots line claiming an owned directive is refused", async () => {
		const made = await panel();
		expect(await save(made, { seo_robots_extra: "# ok\nCrawl-delay: 5\nDisallow: /secret" })).toContain('The line \\"Disallow: /secret\\" sets a directive');
		expect(await made.cms.site().settings.get("seo.robots_extra")).toBeNull();
		await save(made, { seo_robots_extra: "# ok\nCrawl-delay: 5" });
		expect(await made.cms.site().settings.get("seo.robots_extra")).toBe("# ok\nCrawl-delay: 5");
	});

	test("verification stores the token from a pasted tag and refuses a tag for another engine", async () => {
		const made = await panel();
		await save(made, { seo_verification_google: '<meta name="google-site-verification" content="abc123_-" />' });
		expect(await made.cms.site().settings.get("seo.verification.google")).toBe("abc123_-");
		expect(await save(made, { seo_verification_bing: '<meta name="google-site-verification" content="abc" />' })).toContain("does not look like a verification token");
		expect(await made.cms.site().settings.get("seo.verification.bing")).toBeNull();
	});
});

describe("R4-fixes-3: fixtures write redirects like a PHP seeder", () => {
	test("an external target and a redirect over a seeded page path seed without the admin's checks", async () => {
		const { cms } = await makeCms({
			languages: [{ locale: "en", name: "English", default: true }],
			pages: [{ key: "a", name: { en: "About" } }],
			redirects: [
				{ from: "old-shop", to: "https://shop.example.com" },
				{ from: "about", to: "/" },
			],
		});
		expect(await cms.loadPage(new Request("https://site.test/old-shop"))).toEqual({ type: "redirect", status: 301, location: "https://shop.example.com" });
	});
});
