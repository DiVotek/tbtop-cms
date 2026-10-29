import { describe, expect, test } from "bun:test";
import { panel } from "./panel";
import { get, makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

const hits = (db: Awaited<ReturnType<typeof makeCms>>["db"]) =>
	db.selectFrom("tbtop_not_found_hits").select(["path", "locale", "hits", "last_referer"]).orderBy("path").execute();

describe("404 log", () => {
	test("a miss is counted per path; scanner probes and redirected paths are not", async () => {
		const { cms, db } = await makeCms(fixture);
		await cms.site().redirects.create({ fromPath: "old", toPath: "/" });
		await cms.loadPage(get("/gone", { headers: { referer: "https://ref.example/a" } }));
		await cms.loadPage(get("/gone"));
		await cms.loadPage(get("/wp-login.php"));
		await cms.loadPage(get("/old"));

		expect((await hits(db)).map((row) => ({ ...row, hits: Number(row.hits) }))).toEqual([{ path: "gone", locale: "en", hits: 2, last_referer: null }]);
	});

	test("turned off in the SEO settings, nothing is counted; waitUntil gets the write", async () => {
		const { cms, db } = await makeCms(fixture);
		const deferred: Promise<unknown>[] = [];
		await cms.loadPage(get("/later"), { waitUntil: (task) => deferred.push(task) });
		expect(deferred).toHaveLength(1);
		await Promise.all(deferred);
		expect((await hits(db)).map((row) => row.path)).toEqual(["later"]);

		await cms.site().settings.set("seo.not_found.enabled", false);
		await cms.loadPage(get("/missing"));
		expect((await hits(db)).map((row) => row.path)).toEqual(["later"]);
	});

	test("prune drops rows not seen within the retention window", async () => {
		const { cms, db } = await makeCms(fixture);
		await cms.loadPage(get("/stale"));
		await cms.loadPage(get("/fresh"));
		await db.updateTable("tbtop_not_found_hits").set({ last_seen_at: "2020-01-01 00:00:00" }).where("path", "=", "stale").execute();
		await cms.site().settings.set("seo.not_found.retention_days", 30);

		expect(await cms.tasks.pruneNotFound()).toBe(1);
		expect((await hits(db)).map((row) => row.path)).toEqual(["fresh"]);
	});

	test("the admin log lists misses and turns one into a redirect", async () => {
		const { cms, db, post, rows } = await panel(fixture);
		await cms.loadPage(get("/gone"));
		const url = "/admin/not-found";
		const active = await rows("notFoundHits", url, "active");
		expect(active.map((row) => row.path)).toEqual(["gone"]);

		const row = { id: active[0]?.id, path: "gone", locale: "en" };
		const res = await post(`${url}/actions/notFoundCreateRedirectSave`, { payload: { row, form: { locale: "en", to_path: "/", status_code: "301" } } }, url);
		expect(res.status).toBeLessThan(400);
		expect(await cms.loadPage(get("/gone"))).toMatchObject({ type: "redirect", location: "/" });

		await post(`${url}/actions/ignore`, { payload: { row } }, url);
		expect(await rows("notFoundHits", url, "ignored")).toHaveLength(1);
		expect(await db.selectFrom("tbtop_redirects").select("from_path").execute()).toEqual([{ from_path: "gone" }]);
	});
});
