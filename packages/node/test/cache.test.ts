import { describe, expect, test } from "bun:test";
import { kvCache, memoryCache } from "../src";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, settings: { "site.name": "Acme" }, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

async function siteName(cms: Awaited<ReturnType<typeof makeCms>>["cms"]) {
	const result = await cms.loadPage(get("/"));
	if (result.type !== "page") throw new Error(result.type);
	return (result.props.cms as { settings: Record<string, unknown> }).settings["site.name"];
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

	test("kvCache keeps KV's 60-second minimum TTL", async () => {
		const puts: [string, number | undefined][] = [];
		const store = kvCache({ get: async () => null, put: async (key, _value, options) => void puts.push([key, options?.expirationTtl]) });
		await store.set("k", "v", 5);
		expect(puts).toEqual([["k", 60]]);
	});
});
