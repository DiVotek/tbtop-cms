import { currentContext, definePage, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, isLive, pageFromRow } from "../content/page";
import { LEAD_STATUSES, type LeadStatus, submissionFromRow } from "../forms/submissions";
import { leadIdentity } from "../forms/lead";
import { parseDate, sqlDate } from "../support/values";
import { allows } from "./abilities";
import { environmentCards, systemMetrics } from "./dashboardSystem";
import { adminPrefix } from "./support";

const TREND_DAYS = 14;
const PERIODS = [7, 30, 90] as const;
const RECENT = 5;

const LEAD_LABELS: Record<LeadStatus, string> = { new: "New", read: "Read", processed: "Processed", discarded: "Discarded" };
const LEAD_COLORS: Record<LeadStatus, string> = { new: "danger", read: "muted", processed: "success", discarded: "muted" };

/** The `Y-m-d` day of a moment in the admin's timezone (Carbon's toDateString() in app.timezone). */
const dayKey = (date: Date): string => new Intl.DateTimeFormat("en-CA", { timeZone: currentContext().timezone || "UTC" }).format(date);

/** The `Y-m-d` day `offset` days before a `Y-m-d` day. */
const dayBefore = (day: string, offset: number): string => new Date(Date.parse(`${day}T00:00:00Z`) - offset * 86_400_000).toISOString().slice(0, 10);

/** `days` dates ending today in the admin's timezone, oldest first, with the rows created on each (DashboardStats::dailyCounts). */
function daily(createdAt: unknown[], days: number, now = new Date()): { date: string; value: number }[] {
	const counts = new Map<string, number>();
	for (const raw of createdAt) {
		const date = parseDate(raw);
		if (date !== null) {
			counts.set(dayKey(date), (counts.get(dayKey(date)) ?? 0) + 1);
		}
	}
	const today = dayKey(now);
	return Array.from({ length: days }, (_, offset) => {
		const date = dayBefore(today, days - 1 - offset);
		return { date, value: counts.get(date) ?? 0 };
	});
}

/**
 * A stored date at or before the first moment of the window `days` long that
 * ends today: a day early, so a zone ahead of UTC loses no row; daily() drops
 * what falls before the window.
 */
function since(days: number, now = new Date()): string {
	return sqlDate(new Date(Date.parse(`${dayBefore(dayKey(now), days)}T00:00:00Z`)));
}

/** Carbon::diffForHumans, short: "just now", "5 minutes ago", "3 days ago". */
export function ago(date: Date | null, now = new Date()): string {
	if (date === null) {
		return "";
	}
	const seconds = Math.max(0, Math.round((now.getTime() - date.getTime()) / 1000));
	const units: [number, string][] = [
		[31_536_000, "year"],
		[2_592_000, "month"],
		[604_800, "week"],
		[86_400, "day"],
		[3_600, "hour"],
		[60, "minute"],
	];
	for (const [size, name] of units) {
		const n = Math.floor(seconds / size);
		if (n >= 1) {
			return `${n} ${name}${n === 1 ? "" : "s"} ago`;
		}
	}
	return "just now";
}

/**
 * DashboardPage: KPI tiles trended over 14 days, the inbox charts, the
 * latest pages and submissions, and the System section (machine metrics,
 * versions, database). Open to every admin; the submission parts need
 * `tbtop-cms.submissions`. Off the nav, like PHP (reached by URL or the
 * host's home link). Node has no media library, so no Media tile.
 */
/** The view's pages check for its header: @tbtop/admin builds headerActions (sync) right after view, on the same request. */
const canAddPage = new WeakMap<Request, boolean>();

export function dashboard(cms: Cms) {
	const db = cms.config.db;
	const prefix = adminPrefix(cms);
	return definePage({
		path: "dashboard",
		title: "Dashboard",
		subtitle: "Welcome to TableTop CMS.",
		headerActions: (s, { request }) => [
			s.action("dashboardRefresh").label("Refresh").icon("refresh-cw").url(`${prefix}/dashboard`),
			s.action("dashboardViewSite").label("View site").icon("external-link").url(cms.config.url ?? "/").openUrlInNewTab(),
			...(canAddPage.get(request) === true ? [s.action("dashboardNewPage").label("New page").color("primary").url(`${prefix}/pages`)] : []),
		],
		view: async (s, { request }) => {
			const showSubmissions = await allows(cms, "tbtop-cms.submissions");
			const showPages = await allows(cms, "tbtop-cms.pages");
			canAddPage.set(request, showPages);
			const showDesign = await allows(cms, "tbtop-cms.design");
			const fallback = await cms.site(request).languages.defaultLocale();
			const tiles = await stats(s, cms, { showPages, showSubmissions, showDesign });
			return s.stack([
				s.grid({ cols: Math.max(1, tiles.length) }, tiles),
				...(showSubmissions ? [s.grid({ cols: 2 }, charts(s, cms))] : []),
				s.grid({ cols: 2 }, [
					...(showPages
						? [
								s.section({ title: "Recently updated pages", variant: "card", actions: [s.action("dashboardOpenPages").label("Open pages").url(`${prefix}/pages`).link().size("sm")] }, [
									s.list("dashboardRecentPages").items(async () => {
									if (!(await allows(cms, "tbtop-cms.pages"))) {
										return [];
									}
										const rows = await cms.site(request).pages.query().selectAll("tbtop_pages").orderBy("updated_at", "desc").orderBy("id", "desc").limit(RECENT).execute();
										return rows.map((row) => {
											const page = pageFromRow(row);
											return {
												title: firstText(page, ["name"], fallback, fallback) || `#${page.id}`,
												meta: ago(page.updatedAt),
												color: isLive(page) ? "success" : "muted",
												url: `${prefix}/pages/${page.id}/edit`,
											};
										});
									}),
								]),
							]
						: []),
					...(showSubmissions
						? [
								s.section(
									{ title: "Latest submissions", variant: "card", actions: [s.action("dashboardOpenSubmissions").label("Open submissions").url(`${prefix}/form-submissions`).link().size("sm")] },
									[
										s.list("dashboardLatestSubmissions").items(async () => {
											if (!(await allows(cms, "tbtop-cms.submissions"))) {
												return [];
											}
											const rows = await db.selectFrom("tbtop_form_submissions").selectAll().orderBy("created_at", "desc").orderBy("id", "desc").limit(RECENT).execute();
											return rows.map((row) => {
												const submission = submissionFromRow(row);
												return {
													title: leadIdentity(submission.payload) ?? submission.kind,
													meta: `${submission.kind} · ${ago(submission.createdAt)}`,
													color: LEAD_COLORS[submission.leadStatus] ?? "muted",
													url: `${prefix}/form-submissions/${submission.id}`,
												};
											});
										}),
									],
								),
							]
						: []),
				]),
				s.section({ title: "System" }, [s.grid({ cols: 3 }, await systemMetrics(s)), s.grid({ cols: 2 }, environmentCards(s, cms))]),
			]);
		},
	});
}

async function stats(s: S, cms: Cms, { showPages, showSubmissions, showDesign }: { showPages: boolean; showSubmissions: boolean; showDesign: boolean }) {
	const db = cms.config.db;
	const window = since(TREND_DAYS);
	const trend = (rows: { created_at: unknown }[]) =>
		daily(
			rows.map((row) => row.created_at),
			TREND_DAYS,
		).map((day) => day.value);
	const tiles = showPages ? [await pagesTile(s, cms, window, trend)] : [];
	if (showSubmissions) {
		const recent = await db.selectFrom("tbtop_form_submissions").select("created_at").where("created_at", ">=", window).execute();
		const unread = await db.selectFrom("tbtop_form_submissions").select((eb) => eb.fn.countAll().as("n")).where("lead_status", "=", "new").executeTakeFirst();
		const needsReview = Number(unread?.n ?? 0);
		const tile = s.stat("New submissions").value(needsReview).icon("inbox").sparkline(trend(recent), "bottom");
		tiles.push(needsReview > 0 ? tile.description("Need review").color("warning") : tile);
	}
	if (showDesign) {
		const layouts = await db.selectFrom("tbtop_layouts").select("created_at").execute();
		tiles.push(
			s
				.stat("Layouts")
				.value(layouts.length)
				.description(`${layouts.length} layouts`)
				.icon("layout-template")
				.sparkline(trend(layouts), "bottom"),
		);
	}
	return tiles;
}

/** Counts in SQL; only the trend window's rows come back. */
async function pagesTile(s: S, cms: Cms, window: string, trend: (rows: { created_at: unknown }[]) => number[]) {
	const live = cms.config.db.selectFrom("tbtop_pages").where("deleted_at", "is", null);
	const byStatus = await live.select(["status", (eb) => eb.fn.countAll().as("n")]).groupBy("status").execute();
	const count = (status: string) => Number(byStatus.find((row) => row.status === status)?.n ?? 0);
	const published = count("published");
	const draft = count("draft");
	const pages = await live.select("created_at").where("created_at", ">=", window).execute();
	return s
		.stat("Pages")
		.value(published + draft)
		.description(`${published} published · ${draft} draft`)
		.icon("file-text")
		.sparkline(trend(pages), "bottom");
}

function charts(s: S, cms: Cms) {
	const db = cms.config.db;
	return [
		s
			.chart("leads-by-status", "donut")
			.set("title", "Submissions by status")
			.set("nameKey", "label")
			.set("series", [{ dataKey: "value" }])
			// Each submission closure checks the ability itself too, not only the view that leaves it out.
			.query(async () => {
				if (!(await allows(cms, "tbtop-cms.submissions"))) {
					return [];
				}
				const rows = await db
					.selectFrom("tbtop_form_submissions")
					.select(["lead_status", (eb) => eb.fn.countAll().as("n")])
					.groupBy("lead_status")
					.execute();
				const counts = new Map(rows.map((row) => [row.lead_status, Number(row.n)]));
				return LEAD_STATUSES.map((status) => ({ label: LEAD_LABELS[status], value: counts.get(status) ?? 0 }));
			}),
		s
			.chart("submissions-daily", "area")
			.set("title", "Submissions over time")
			.set("xKey", "date")
			.set("series", [{ dataKey: "value", label: "Submissions" }])
			.params([
				s
					.select("period")
					.label("Period")
					.default(String(PERIODS[1]))
					.options(PERIODS.map((days) => ({ value: String(days), label: `Last ${days} days` }))),
			])
			.query(async (_request, params) => {
				if (!(await allows(cms, "tbtop-cms.submissions"))) {
					return [];
				}
				const asked = Number(params.period);
				const days = (PERIODS as readonly number[]).includes(asked) ? asked : PERIODS[1];
				const rows = await db.selectFrom("tbtop_form_submissions").select("created_at").where("created_at", ">=", since(days)).execute();
				return daily(
					rows.map((row) => row.created_at),
					days,
				);
			}),
	];
}
