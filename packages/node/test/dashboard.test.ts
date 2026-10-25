import { describe, expect, test } from "bun:test";
import { ago } from "../src/admin/dashboard";
import { panel } from "./panel";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = {
	languages: en,
	pages: [
		{ key: "home", system: true, name: { en: "Home" } },
		{ key: "draft", name: { en: "Draft page" }, status: "draft" as const, slug: { en: "draft" } },
	],
};

type Wire = { kind: string; name?: string; options: Record<string, unknown> };

/** The System section's own tiles and lists, apart from the KPI row and the recent lists. */
const SYSTEM_STATS = ["CPU load", "Memory", "Disk"];
const kpis = (all: Wire[]) => all.filter((node) => node.kind === "stat" && !SYSTEM_STATS.includes(String(node.options.label)));
const recentLists = (all: Wire[]) => all.filter((node) => node.kind === "list" && !String(node.name).startsWith("dashboardSystem"));

/** Every `{kind, options}` node of a serialized tree, depth first. */
function nodes(tree: unknown): Wire[] {
	if (Array.isArray(tree)) {
		return tree.flatMap(nodes);
	}
	if (tree === null || typeof tree !== "object") {
		return [];
	}
	const own = "kind" in tree && "options" in tree ? [tree as Wire] : [];
	return [...own, ...Object.values(tree).flatMap(nodes)];
}

describe("dashboard", () => {
	test("stat tiles, the recent pages and submissions, and the inbox charts", async () => {
		const { cms, page, visit } = await panel(fixture);
		const site = cms.site();
		const home = (await site.pages.query().select("id").where("is_system", "=", 1).executeTakeFirstOrThrow()).id;
		await site.submissions.create({ pageId: Number(home), blockId: "b1", kind: "contact", locale: "en", payload: { name: "Ada", email: "ada@example.com" } });

		const props = (await page("/admin/dashboard")).props as Record<string, unknown>;
		const all = nodes(props);
		const stats = kpis(all).map((node) => [node.options.label, node.options.value]);
		expect(stats).toEqual([
			["Pages", 2],
			["New submissions", 1],
			["Layouts", 0],
		]);
		const lists = recentLists(all);
		const items = lists.map((list) => (list.options.items as { title: string; color: string }[]).map((item) => `${item.title}:${item.color}`));
		expect(items.map((list) => list.toSorted())).toEqual([["Draft page:muted", "Home:success"], ["Ada · ada@example.com:danger"]]);

		const donut = (await (await visit("/admin/dashboard/data/leads-by-status", { inertia: false })).json()) as { data: { label: string; value: number }[] };
		expect(donut.data).toEqual([
			{ label: "New", value: 1 },
			{ label: "Read", value: 0 },
			{ label: "Processed", value: 0 },
			{ label: "Discarded", value: 0 },
		]);
		const daily = (await (await visit("/admin/dashboard/data/submissions-daily?period=7", { inertia: false })).json()) as { data: { date: string; value: number }[] };
		expect(daily.data).toHaveLength(7);
		expect(daily.data.at(-1)).toEqual({ date: new Date().toISOString().slice(0, 10), value: 1 });
	});

	test("without the inbox ability the submission tiles, charts and list are left out", async () => {
		const { page, visit } = await panel(fixture, { authorize: (_user, ability) => ability !== "tbtop-cms.submissions" });
		const all = nodes((await page("/admin/dashboard")).props);
		// The view is built per request, so the data endpoints cannot reach what it left out.
		for (const name of ["leads-by-status", "submissions-daily"]) {
			expect((await visit(`/admin/dashboard/data/${name}`, { inertia: false })).status).toBe(404);
		}
		expect(kpis(all).map((node) => node.options.label)).toEqual(["Pages", "Layouts"]);
		expect(all.filter((node) => node.kind.startsWith("chart"))).toEqual([]);
		expect(recentLists(all)).toHaveLength(1);
		const header = (await panel(fixture, { authorize: (_user, ability) => ability !== "tbtop-cms.pages" })).page;
		expect(JSON.stringify((await header("/admin/dashboard")).props)).not.toContain("dashboardNewPage");
		expect(JSON.stringify((await (await panel(fixture)).page("/admin/dashboard")).props)).toContain("dashboardNewPage");
	});

	test("the System section: machine tiles, versions and the database", async () => {
		const all = nodes((await (await panel(fixture)).page("/admin/dashboard")).props);
		const system = all.filter((node) => node.kind === "stat" && SYSTEM_STATS.includes(String(node.options.label)));
		expect(system.map((node) => node.options.label)).toEqual(SYSTEM_STATS);
		const lists: Record<string, string[][]> = Object.fromEntries(
			all.filter((node) => String(node.name).startsWith("dashboardSystem")).map((node) => [node.name, (node.options.items as { title: string; meta: string }[]).map((item) => [item.title, item.meta])]),
		);
		expect(lists.dashboardSystemVersions?.map(([title]) => title)).toEqual(["Runtime", "@tbtop/admin version", "@tbtop/cms version"]);
		expect(lists.dashboardSystemVersions?.[0]?.[1]).toStartWith("Bun ");
		expect(lists.dashboardSystemInfrastructure?.[0]).toEqual(["Database driver", process.env.TBTOP_TEST_DB ?? "sqlite"]);
		expect(lists.dashboardSystemInfrastructure?.[1]?.[1]).toMatch(/^\d+(\.\d)? (B|KB|MB|GB)$/);
	});

	test("relative times", () => {
		const now = new Date("2026-10-23T12:00:00Z");
		expect(ago(new Date("2026-10-23T11:59:40Z"), now)).toBe("just now");
		expect(ago(new Date("2026-10-23T11:55:00Z"), now)).toBe("5 minutes ago");
		expect(ago(new Date("2026-10-22T12:00:00Z"), now)).toBe("1 day ago");
		expect(ago(null, now)).toBe("");
	});

	test("without the pages ability the page tile and the recent pages are left out", async () => {
		const { page } = await panel(fixture, { authorize: (_user, ability) => ability !== "tbtop-cms.pages" });
		const all = nodes((await page("/admin/dashboard")).props);
		expect(kpis(all).map((node) => node.options.label)).toEqual(["New submissions", "Layouts"]);
		expect(recentLists(all)).toHaveLength(1);
	});

	test("without the design ability the layout tile is left out", async () => {
		const { page } = await panel(fixture, { authorize: (_user, ability) => ability !== "tbtop-cms.design" });
		const all = nodes((await page("/admin/dashboard")).props);
		expect(kpis(all).map((node) => node.options.label)).toEqual(["Pages", "New submissions"]);
	});
});
