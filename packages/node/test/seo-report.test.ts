import { describe, expect, test } from "bun:test";
import type { Fixture } from "../src";
import { runCommand } from "../src/cli/run";
import { runSeoAudit, seoSnapshots } from "../src/seo/audit";
import { panel } from "./panel";
import { makeCms } from "./support";

const languages = [
	{ locale: "en", name: "English", default: true },
	{ locale: "de", name: "Deutsch", frontend: true },
];
const fixture: Fixture = {
	languages,
	pages: [
		{ key: "home", system: true, name: { en: "Home", de: "Start" }, summary: { en: "Welcome", de: "Willkommen" }, image: "home.jpg" },
		{ key: "a", name: { en: "Services" }, summary: { en: "What we do" }, slug: { en: "a", de: "a" }, image: "a.jpg" },
		{ key: "b", name: { en: "Services" }, summary: { en: "More" }, slug: { en: "b", de: "b" }, indexable: false, image: "b.jpg" },
		{ key: "c", name: { en: "Contact" }, slug: { en: "c", de: "c" }, image: "c.jpg" },
		{ key: "draft", name: { en: "Draft" }, status: "draft", slug: { en: "draft" } },
	],
};

const row = (issue: { severity: string; key: string; page: string; locale: string }) => `${issue.severity} ${issue.key} ${issue.page} ${issue.locale}`.trim();

describe("SEO report", () => {
	test("the audit checks every live page in every frontend locale, fails first", async () => {
		const { cms } = await makeCms(fixture);
		const { issues, pagesScanned } = await runSeoAudit(cms.site());

		expect(pagesScanned).toBe(4);
		expect(issues.map(row)).toEqual([
			"fail missing_description Contact de",
			"fail missing_description Contact en",
			"warn missing_translation Contact de",
			"warn duplicate_title Services de",
			"warn duplicate_title Services de",
			"warn duplicate_title Services en",
			"warn duplicate_title Services en",
			"warn missing_translation Services de",
			"warn missing_translation Services de",
			"warn noindex Services",
		]);
		expect(issues.find((issue) => issue.key === "duplicate_title" && issue.locale === "en")?.detail).toBe("Same title as: Services.");
	});

	test("a page without an image of its own and no site default misses its OG image", async () => {
		const { cms } = await makeCms({ languages: languages.slice(0, 1), pages: [{ key: "home", system: true, name: { en: "Home" }, summary: { en: "Hi" } }] });
		expect((await runSeoAudit(cms.site())).issues.map(row)).toEqual(["warn missing_og_image Home"]);

		await cms.site().settings.set("seo.default_og_image", "default.jpg");
		expect((await runSeoAudit(cms.site())).issues).toEqual([]);
	});

	test("the admin report writes today's snapshot; the CLI overwrites it", async () => {
		const { cms, page } = await panel(fixture);
		const props = JSON.stringify((await page("/admin/seo-report")).props);
		expect(props).toContain("10 problem(s) on 3 page(s).");
		expect(props).toContain("Contact · 2 failures · 1 warning");

		const out: string[] = [];
		expect(await runCommand(cms, ["seo:snapshot"], { out: (line) => out.push(line), err: (line) => out.push(line) })).toBe(0);
		expect(out).toEqual(["SEO snapshot written: 10 issues."]);
		const [today] = await seoSnapshots(cms.site());
		expect(await seoSnapshots(cms.site())).toHaveLength(1);
		expect(today).toMatchObject({ date: new Date().toISOString().slice(0, 10), pagesScanned: 4, pagesWithIssues: 3, failCount: 2, warnCount: 8 });
		expect(today?.issueCounts).toEqual({ missing_description: 2, missing_translation: 3, duplicate_title: 4, noindex: 1 });
	});
});
