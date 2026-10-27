import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { panel } from "./panel";
import { get, makeCms } from "./support";

describe("<title> in a translated page (#311, #326)", () => {
	test("a blank meta title falls back to the page's own locale name, never another language's", async () => {
		const { cms, ids } = await makeCms();
		const site = cms.site();
		await site.pages.update(ids.about as number, { seo: { title: { en: "About the studio" } } });
		const paths = await site.pages.slugs.allFor(ids.about as number);
		const uk = await cms.loadPage(get(`/uk/${paths.uk}`));
		const en = await cms.loadPage(get(`/${paths.en}`));
		expect(uk.type === "page" && uk.props.seo.ownTitle).toBe("Про нас");
		expect(en.type === "page" && en.props.seo.ownTitle).toBe("About the studio");
	});
});

describe("the first publish stamps published_at (#310)", () => {
	const minute = 60_000;

	test("a page published with no date gets now; a republish keeps the first stamp", async () => {
		const { cms } = await makeCms(null);
		const pages = cms.site().pages;
		const created = await pages.create({ type: "page", name: { en: "Fresh" }, status: "published" });
		expect(Math.abs((created.publishedAt?.getTime() ?? 0) - Date.now())).toBeLessThan(minute);

		const draft = await pages.create({ type: "page", name: { en: "Later" } });
		expect(draft.publishedAt).toBeNull();
		expect((await pages.update(draft.id, { name: { en: "Later, renamed" } })).publishedAt).toBeNull();
		const published = await pages.update(draft.id, { status: "published" });
		expect(published.publishedAt).not.toBeNull();

		const stamp = published.publishedAt?.getTime();
		await pages.update(draft.id, { status: "draft" });
		expect((await pages.update(draft.id, { status: "published" })).publishedAt?.getTime()).toBe(stamp);
	});

	test("a date the editor sets, a future one included, is left alone", async () => {
		const { cms } = await makeCms(null);
		const pages = cms.site().pages;
		const future = new Date(Date.now() + 7 * 24 * 3600 * 1000);
		future.setMilliseconds(0);
		const scheduled = await pages.create({ type: "page", name: { en: "Soon" }, status: "published", publishedAt: future });
		expect(scheduled.publishedAt?.getTime()).toBe(future.getTime());
	});
});

type Filter = { name: string; options: { options: { value: string }[] } };

/** The table filter named `name`, wherever the structure nests it. */
function findFilter(node: unknown, name: string): Filter | undefined {
	if (Array.isArray(node)) {
		return node.map((child) => findFilter(child, name)).find((found) => found !== undefined);
	}
	if (typeof node !== "object" || node === null) {
		return undefined;
	}
	const filters = (node as { filters?: Filter[] }).filters;
	return filters?.find((filter) => filter.name === name) ?? findFilter(Object.values(node), name);
}

describe("the pages list filters (#310)", () => {
	const ids = (rows: Record<string, unknown>[]) => rows.map((row) => Number(row.id)).sort((a, b) => a - b);
	const table = async (visit: Awaited<ReturnType<typeof panel>>["visit"], query: string) =>
		((await (await visit(`/admin/pages/tables/pages?${query}`, { inertia: false })).json()) as { data: { data: Record<string, unknown>[] } }).data.data;

	test("parent offers only pages with children and keeps their children", async () => {
		const { page, visit, ids: made } = await panel();
		const parent = findFilter((await page("/admin/pages")).props.structure, "parent_id");
		expect(parent?.options.options.map((option) => Number(option.value))).toEqual([Number(made.blog)]);
		expect(ids(await table(visit, `filters[parent_id]=${made.blog}`))).toEqual([Number(made["first-post"]), Number(made["scheduled-post"])].sort((a, b) => a - b));
	});

	test("status and SEO match what the row badges say", async () => {
		const { visit, cms, ids: made } = await panel();
		await cms.site().pages.update(made.about as number, { summary: { en: "", uk: "" } });
		const drafts = await table(visit, "filters[display_state]=draft");
		expect(drafts.map((row) => row.display_state)).toEqual(drafts.map(() => "Draft"));
		expect(ids(drafts)).toContain(Number(made.draft));
		const warned = await table(visit, "filters[seo_state]=warn");
		expect(ids(warned)).toContain(Number(made.about));
		expect(warned.map((row) => row.seo_state)).toEqual(warned.map(() => "Needs work"));
		expect(ids(await table(visit, "filters[seo_state]=pass"))).not.toContain(Number(made.about));
	});

	test("a summary that is not a locale map counts as missing in the badge, its tooltip and the filter alike", async () => {
		const { visit, db, ids: made } = await panel();
		await db.updateTable("tbtop_pages").set({ summary: JSON.stringify("Who we are.") }).where("id", "=", made.about as number).execute();
		const [row] = (await table(visit, "search=About")).filter((one) => Number(one.id) === made.about);
		expect(row?.seo_state).toBe("Needs work");
		expect((row?._tooltips as Record<string, string>).seo_state).toBe("No summary");
		expect(ids(await table(visit, "filters[seo_state]=warn"))).toContain(Number(made.about));
	});
});

describe("the page title lives only in SEO (#326)", () => {
	const fieldNames = (node: unknown): string[] => {
		if (Array.isArray(node)) {
			return node.flatMap(fieldNames);
		}
		if (typeof node !== "object" || node === null) {
			return [];
		}
		const own = (node as { kind?: unknown; name?: unknown }).name;
		return [...(typeof own === "string" ? [own] : []), ...Object.values(node).flatMap(fieldNames)];
	};

	test("neither the editor nor the create dialog has a Title field; the SEO tab keeps Meta title", async () => {
		const { page, ids } = await panel();
		const editor = fieldNames((await page(`/admin/pages/${ids.about}/edit`)).props);
		expect(editor).not.toContain("title");
		expect(editor).toContain("seo_title");
		expect(fieldNames((await page("/admin/pages")).props)).not.toContain("title");
	});
});

describe("ctx.mediaImage (#314)", () => {
	const loader = (ref: string, width: number) => `https://cdn.test/w${width}/${ref}`;
	const media = (ref: string) => (ref === "missing" ? null : { url: `https://cdn.test/${ref}`, alt: "Banner", ...(ref === "svg" ? {} : { width: ref === "small" ? 500 : 1600, height: 800 }) });

	test("a srcset of loader copies narrower than the original, then the original", async () => {
		const { cms } = await makeCms(null, { media, imageLoader: loader });
		const ctx = await cms.site().resolveContext("en", null);
		expect(ctx.mediaImage("hero.png")).toEqual({
			src: "https://cdn.test/hero.png",
			srcset: "https://cdn.test/w640/hero.png 640w, https://cdn.test/w960/hero.png 960w, https://cdn.test/w1280/hero.png 1280w, https://cdn.test/hero.png 1600w",
			width: 1600,
			height: 800,
			alt: "Banner",
		});
	});

	test("no loader, no width or no narrower step: src alone; an unknown ref is null", async () => {
		const plain = await (await makeCms(null, { media })).cms.site().resolveContext("en", null);
		expect(plain.mediaImage("hero.png")?.srcset).toBeNull();
		const ctx = await (await makeCms(null, { media, imageLoader: loader })).cms.site().resolveContext("en", null);
		expect(ctx.mediaImage("svg")).toMatchObject({ src: "https://cdn.test/svg", srcset: null, width: null });
		expect(ctx.mediaImage("small")?.srcset).toBeNull();
		expect(ctx.mediaImage("missing")).toBeNull();
	});
});

describe("the admin UI locale is each admin's own, saved to the profile (#309)", () => {
	const locales = { admin: { locales: ["en", "uk"], locale: "en" } };

	async function signedIn(config: Parameters<typeof makeCms>[1]) {
		const made = await makeCms(null, config);
		const admin = createAdmin({ ...made.cms.admin.options(), user: () => ({ id: 7, name: "Ann" }) });
		const visit = async (url: string, init: RequestInit = {}) => {
			const headers = new Headers(init.headers);
			headers.set("x-inertia", "true");
			const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
			if (res === null) {
				throw new TypeError(`${url} fell through`);
			}
			return res;
		};
		return { ...made, visit };
	}

	test("a pick in the user menu is stored on the profile and read back in a fresh session", async () => {
		const { db, visit } = await signedIn(locales);
		const switched = await visit("/admin/locale", { method: "POST", body: JSON.stringify({ locale: "uk" }), headers: { "content-type": "application/json", referer: "http://x/admin" } });
		expect(switched.status).toBeLessThan(400);
		const profile = await db.selectFrom("tbtop_user_profiles").select(["user_id", "locale"]).executeTakeFirst();
		expect([String(profile?.user_id), profile?.locale]).toEqual(["7", "uk"]);
		// No cookie is sent back, so this is a new session: the locale comes from the profile.
		const page = (await (await visit("/admin/pages")).json()) as InertiaPage;
		const shared = page.props.tbtop as { locale: string; locales: string[] };
		expect([shared.locale, shared.locales]).toEqual(["uk", ["en", "uk"]]);
		expect(JSON.stringify(shared)).toContain('"userMenu"');
		expect(JSON.stringify(shared)).not.toContain('"locales":false');
	});

	test("the template editor offers the admin locales, not the content languages", async () => {
		const { visit } = await signedIn({ admin: { locales: ["en", "de"] } });
		const props = JSON.stringify(((await (await visit("/admin/notification-settings")).json()) as InertiaPage).props);
		expect(props).toContain("tpl__form_submission__html__de__body");
		expect(props).not.toContain("tpl__form_submission__html__uk__body");
	});

	test("one locale needs no profile: nothing is read or written", async () => {
		const { db, visit } = await signedIn({});
		await visit("/admin/locale", { method: "POST", body: JSON.stringify({ locale: "en" }), headers: { "content-type": "application/json", referer: "http://x/admin" } });
		expect(await db.selectFrom("tbtop_user_profiles").selectAll().execute()).toEqual([]);
	});
});
