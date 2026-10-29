import { definePage, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { runSeoAudit, type SeoIssue, seoSnapshots, writeSeoSnapshot } from "../seo/audit";
import { listHeader, NAV } from "./support";

const fails = (issues: SeoIssue[]): number => issues.filter((issue) => issue.severity === "fail").length;
const plural = (n: number, word: string) => `${n} ${word}${n === 1 ? "" : "s"}`;

/**
 * SeoReportPage: the site-wide audit, run on every visit, which also
 * writes today's snapshot. Overview: summary, tiles (trended once two
 * snapshots exist), severity and issue-type charts, the 90-day history.
 * Details: one collapsible per page, fails first.
 */
export function seoReport(cms: Cms) {
	return definePage({
		path: "seo-report",
		...listHeader("content", "SEO report", "Site-wide SEO issues across every published page and active locale."),
		nav: { group: NAV.content, label: "SEO report", icon: "search-check", order: 40 },
		view: async (s, { request }) => {
			const site = cms.site(request);
			const result = await runSeoAudit(site);
			await writeSeoSnapshot(site, result);
			const history = await seoSnapshots(site);
			return s.tabs([
				{ label: "Overview", children: [overview(s, result.issues, result.pagesScanned, history)] },
				{ label: "Details", children: [details(s, result.issues)] },
			]);
		},
	});
}

function overview(s: S, issues: SeoIssue[], pagesScanned: number, history: Awaited<ReturnType<typeof seoSnapshots>>) {
	const failCount = fails(issues);
	const pagesWithIssues = new Set(issues.map((issue) => issue.pageId)).size;
	const recent = history.slice(-30);
	const trend = recent.length >= 2;
	const tile = (label: string, value: number, series: number[]) => {
		const stat = s.stat(label).value(value);
		return trend ? stat.sparkline(series) : stat;
	};
	const byType = new Map<string, number>();
	for (const issue of issues) {
		byType.set(issue.issue, (byType.get(issue.issue) ?? 0) + 1);
	}
	const summary =
		issues.length === 0
			? s.displayAlert("No SEO issues found across published pages.").color("success")
			: s.displayAlert(`${issues.length} problem(s) on ${pagesWithIssues} page(s).`).color(failCount > 0 ? "danger" : "warning");
	return s.stack([
		summary,
		s.grid({ cols: 4 }, [
			tile(
				"Pages scanned",
				pagesScanned,
				recent.map((row) => row.pagesScanned),
			),
			tile(
				"Pages with issues",
				pagesWithIssues,
				recent.map((row) => row.pagesWithIssues),
			),
			tile(
				"Fail",
				failCount,
				recent.map((row) => row.failCount),
			),
			tile(
				"Warning",
				issues.length - failCount,
				recent.map((row) => row.warnCount),
			),
		]),
		s.grid({ cols: 2 }, [
			s
				.chart("seo-report-by-severity", "donut")
				.set("title", "Issues by severity")
				.set("nameKey", "label")
				.set("series", [{ dataKey: "value" }])
				.query(() => [
					{ label: "Fail", value: failCount },
					{ label: "Warning", value: issues.length - failCount },
				]),
			s
				.chart("seo-report-by-issue-type", "bar")
				.set("title", "Issues by type")
				.set("xKey", "label")
				.set("series", [{ dataKey: "value", label: "Count" }])
				.query(() => [...byType].sort((a, b) => b[1] - a[1]).map(([label, value]) => ({ label, value }))),
		]),
		s
			.chart("seo-report-history", "line")
			.set("title", "Issue history")
			.set("xKey", "date")
			.set("series", [
				{ dataKey: "fail_count", label: "Fail" },
				{ dataKey: "warn_count", label: "Warning" },
				{ dataKey: "pages_with_issues", label: "Pages with issues" },
			])
			.query(() => history.map((row) => ({ date: row.date, fail_count: row.failCount, warn_count: row.warnCount, pages_with_issues: row.pagesWithIssues }))),
		s.displayText("History accumulates from the first time this report is opened, plus each daily `cms.tasks.scheduled()` run.").variant("muted"),
	]);
}

function details(s: S, issues: SeoIssue[]) {
	if (issues.length === 0) {
		return s.displayAlert("Every published page passed every check.").title("No issues found").color("success");
	}
	const groups = new Map<number, SeoIssue[]>();
	for (const issue of issues) {
		groups.set(issue.pageId, [...(groups.get(issue.pageId) ?? []), issue]);
	}
	const ordered = [...groups].sort(([, a], [, b]) => (fails(a) > 0 !== fails(b) > 0 ? (fails(a) > 0 ? -1 : 1) : b.length - a.length));
	return s.stack(
		ordered.map(([pageId, pageIssues]) => {
			const failCount = fails(pageIssues);
			const warnCount = pageIssues.length - failCount;
			const label = [pageIssues[0]?.page ?? `#${pageId}`, ...(failCount > 0 ? [plural(failCount, "failure")] : []), ...(warnCount > 0 ? [plural(warnCount, "warning")] : [])].join(" · ");
			return s.collapsible({ label, collapsed: true }, [
				s.list(`page-issues-${pageId}`).items(() =>
					pageIssues.map((issue) => ({
						title: issue.issue,
						meta: issue.locale === "" ? issue.detail : `${issue.detail} (${issue.locale})`,
						color: issue.severity === "fail" ? "danger" : "warning",
					})),
				),
			]);
		}),
	);
}
