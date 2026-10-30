import { afterEach, describe, expect, setSystemTime, test } from "bun:test";
import { type CmsCacheStore, kvCache, memoryCache } from "../src";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, settings: { "site.name": "Acme" }, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

async function siteName(cms: Awaited<ReturnType<typeof makeCms>>["cms"]) {
	const result = await cms.loadPage(get("/"));
	if (result.type !== "page") throw new Error(result.type);
	return (result.props.cms as { settings: Record<string, unknown> }).settings.name;
}

describe("content cache", () => {
	test("frontend reads come from the cache; a write through the CMS shows at once", async () => {
		const { cms, db } = await makeCms(fixture, { cache: { store: memoryCache() } });
		expect(await siteName(cms)).toBe("Acme");

		// A write behind the CMS's back is not seen until the cache turns over...
		await db.updateTable("tbtop_settings").set({ value: '"Changed"' }).where("key", "=", "site.name").execute();
		expect(await siteName(cms)).toBe("Acme");
		// ...while the admin reads live,
		expect(await cms.site().settings.get("site.name")).toBe("Changed");
		// and any write through the CMS bumps the version.
		await cms.site().settings.set("site.name", "Renamed");
		expect(await siteName(cms)).toBe("Renamed");
	});

	test("a language change bumps it too; without a store nothing is cached", async () => {
		const { cms } = await makeCms({ ...fixture, languages: [...en, { locale: "de", name: "Deutsch", frontend: false }] }, { cache: { store: memoryCache() } });
		expect((await cms.loadPage(get("/de"))).type).toBe("notFound");
		const de = await cms.site().languages.find("de");
		await cms.site().pages.slugs.backfillLocale("de");
		await cms.site().languages.update(de?.id as number, { isAdminActive: true, isFrontendActive: true });
		expect((await cms.loadPage(get("/de"))).type).toBe("page");

		const plain = await makeCms(fixture);
		await plain.db.updateTable("tbtop_settings").set({ value: '"Live"' }).where("key", "=", "site.name").execute();
		expect(await siteName(plain.cms)).toBe("Live");
	});

	test("the scheduler's own bookkeeping (system.* keys) does not empty the cache every minute", async () => {
		const { cms, db } = await makeCms(fixture, { cache: { store: memoryCache() } });
		expect(await siteName(cms)).toBe("Acme");
		await db.updateTable("tbtop_settings").set({ value: '"Changed"' }).where("key", "=", "site.name").execute();
		await cms.tasks.scheduled();
		expect(await siteName(cms)).toBe("Acme");
		expect(await cms.site().settings.get("system.scheduler.heartbeat")).toBeString();
	});

	test("kvCache keeps KV's 60-second minimum TTL", async () => {
		const puts: [string, number | undefined][] = [];
		const store = kvCache({ get: async () => null, put: async (key, _value, options) => void puts.push([key, options?.expirationTtl]) });
		await store.set("k", "v", 5);
		expect(puts).toEqual([["k", 60]]);
	});
});

const site = {
	languages: en,
	settings: { "site.name": "Acme" },
	sharedBlocks: [{ key: "contact", name: "Contact", kind: "ContactForm", data: { heading: { en: "Write to us" } } }],
	pages: [
		{ key: "home", system: true, name: { en: "Home" } },
		{ key: "about", name: { en: "About" } },
	],
	layout: { header: [{ kind: "Hero", data: { title: { en: "Top" } } }], footer: [{ kind: "ContactForm", shared: "contact" }] },
};

type Made = Awaited<ReturnType<typeof makeCms>>;

async function shared(cms: Made["cms"], path = "/") {
	const result = await cms.loadPage(get(path));
	if (result.type !== "page") throw new Error(result.type);
	return result.props.cms as { layout: Record<"header" | "footer", { props: Record<string, unknown> }[]> };
}

const sitemap = async (cms: Made["cms"]) => (await cms.handle(get("/sitemap-1.xml")))?.text();

/** A memoryCache that counts its writes. */
function countingCache(): { store: CmsCacheStore; writes: () => number } {
	const inner = memoryCache();
	let writes = 0;
	return {
		store: { get: (key) => inner.get(key), set: (key, value, ttl) => (writes++, inner.set(key, value, ttl)) },
		writes: () => writes,
	};
}

describe("internal reads under the content cache", () => {
	afterEach(() => setSystemTime());

	test("the layout resolves once per locale; its form action still follows the page on screen", async () => {
		const { cms, db, ids } = await makeCms(site, { cache: { store: memoryCache() } });
		expect((await shared(cms)).layout.header[0]?.props.title).toBe("Top");
		await db.updateTable("tbtop_layouts").set({ content: JSON.stringify([{ id: "x", kind: "Hero", data: { title: { en: "Behind" } } }]) }).where("slot", "=", "header").execute();
		expect((await shared(cms)).layout.header[0]?.props.title).toBe("Top");

		const home = (await shared(cms, "/")).layout.footer[0]?.props.action;
		const about = (await shared(cms, "/about")).layout.footer[0]?.props.action;
		expect(home).toContain(`/${ids.home}/`);
		expect(about).toContain(`/${ids.about}/`);

		await cms.site().layouts.save("header", [{ id: "h", kind: "Hero", data: { title: { en: "Saved" } } }]);
		expect((await shared(cms)).layout.header[0]?.props.title).toBe("Saved");
	});

	test.each([
		["a page", async ({ cms, ids }: Made) => void (await cms.site().pages.update(ids.home as number, { name: { en: "Home page" } }))],
		["a layout", async ({ cms }: Made) => void (await cms.site().layouts.save("footer", []))],
		["a redirect", async ({ cms }: Made) => void (await cms.site().redirects.create({ fromPath: "/old", toPath: "/about" }))],
		["a shared block", async ({ cms }: Made) => {
			const [block] = await cms.site().sharedBlocks.all();
			await cms.site().sharedBlocks.update(block?.id as number, { data: { heading: { en: "Hello" } } });
		}],
	])("an edit to %s bumps the version", async (_name, edit) => {
		const made = await makeCms(site, { cache: { store: memoryCache() } });
		expect(await sitemap(made.cms)).toContain("https://site.test/about");
		await made.db.updateTable("tbtop_pages").set({ is_indexable: 0 }).where("id", "=", made.ids.about as number).execute();
		expect(await sitemap(made.cms)).toContain("https://site.test/about");
		await edit(made);
		expect(await sitemap(made.cms)).not.toContain("https://site.test/about");
	});

	test("llms.txt is cached until a write", async () => {
		const { cms, db } = await makeCms(site, { cache: { store: memoryCache() } });
		await cms.site().settings.set("seo.llms_txt.enabled", true);
		expect(await (await cms.handle(get("/llms.txt")))?.text()).toStartWith("# Acme");
		await db.updateTable("tbtop_settings").set({ value: '"Behind"' }).where("key", "=", "site.name").execute();
		expect(await (await cms.handle(get("/llms.txt")))?.text()).toStartWith("# Acme");
		await cms.site().settings.set("site.name", "Renamed");
		expect(await (await cms.handle(get("/llms.txt")))?.text()).toStartWith("# Renamed");
	});

	test("a scheduled page shows when its published_at passes, with no write and no cron", async () => {
		const now = new Date("2026-11-02T10:00:00Z");
		setSystemTime(now);
		const { cms } = await makeCms(
			{ ...site, pages: [...site.pages, { key: "soon", name: { en: "Soon" }, publishedAt: "2026-11-02T10:05:00Z" }, { key: "later", name: { en: "Later" }, publishedAt: "2027-01-01T00:00:00Z" }] },
			{ cache: { store: memoryCache() } },
		);
		expect(await sitemap(cms)).not.toContain("/soon");
		setSystemTime(new Date("2026-11-02T10:04:59Z"));
		expect(await sitemap(cms)).not.toContain("/soon");
		setSystemTime(new Date("2026-11-02T10:05:01Z"));
		expect(await sitemap(cms)).toContain("/soon");
		expect(await sitemap(cms)).not.toContain("/later");
	});

	test("a warm read writes nothing to the store", async () => {
		const cache = countingCache();
		const { cms } = await makeCms(site, { cache: { store: cache.store } });
		await shared(cms);
		await sitemap(cms);
		const warm = cache.writes();
		await shared(cms);
		await shared(cms, "/about");
		await sitemap(cms);
		expect(cache.writes()).toBe(warm);
	});
});
