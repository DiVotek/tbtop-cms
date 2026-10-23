import { definePage, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, isLive, pageFromRow } from "../content/page";
import { LEAD_STATUSES, type LeadStatus, submissionFromRow } from "../forms/submissions";
import { leadIdentity } from "../forms/lead";
import { parseDate, sqlDate } from "../support/values";
import { allows } from "./abilities";
import { adminPrefix } from "./support";

const TREND_DAYS = 14;
const PERIODS = [7, 30, 90] as const;
const RECENT = 5;

const LEAD_LABELS: Record<LeadStatus, string> = { new: "New", read: "Read", processed: "Processed", discarded: "Discarded" };
const LEAD_COLORS: Record<LeadStatus, string> = { new: "danger", read: "muted", processed: "success", discarded: "muted" };

const dayKey = (date: Date): string => date.toISOString().slice(0, 10);

/** `days` UTC dates ending today, oldest first, with the rows created on each (DashboardStats::dailyCounts). */
function daily(createdAt: unknown[], days: number, now = new Date()): { date: string; value: number }[] {
	const counts = new Map<string, number>();
	for (const raw of createdAt) {
		const date = parseDate(raw);
		if (date !== null) {
			counts.set(dayKey(date), (counts.get(dayKey(date)) ?? 0) + 1);
		}
	}
	const today = Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate());
	return Array.from({ length: days }, (_, offset) => {
		const date = dayKey(new Date(today - (days - 1 - offset) * 86_400_000));
		return { date, value: counts.get(date) ?? 0 };
	});
}

/** The first moment of the window `days` long that ends today, as a stored date. */
function since(days: number, now = new Date()): string {
	const start = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth(), now.getUTCDate()) - (days - 1) * 86_400_000);
	return sqlDate(start);
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
 * latest pages and submissions. Open to every admin; the submission parts
 * need `tbtop-cms.submissions`. Off the nav, like PHP (reached by URL or
 * the host's home link). Media and server metrics are the host's, so the
 * PHP media tile and system section have no counterpart.
 */
export function dashboard(cms: Cms) {
	const db = cms.config.db;
	const prefix = adminPrefix(cms);
	return definePage({
		path: "dashboard",
		title: "Dashboard",
		subtitle: "Welcome back",
		headerActions: (s) => [
			s.action("dashboardRefresh").label("Refresh").icon("refresh-cw").url(`${prefix}/dashboard`),
			s.action("dashboardViewSite").label("View site").icon("external-link").url(cms.config.url ?? "/").openUrlInNewTab(),
			s.action("dashboardNewPage").label("New page").color("primary").url(`${prefix}/pages`),
		],
		view: async (s, { request }) => {
			const showSubmissions = await allows(cms, "tbtop-cms.submissions");
			const showPages = await allows(cms, "tbtop-cms.pages");
			const showDesign = await allows(cms, "tbtop-cms.design");
			const fallback = await cms.site(request).languages.defaultLocale();
			return s.stack([
				s.grid({ cols: 3 }, await stats(s, cms, { showPages, showSubmissions, showDesign })),
				...(showSubmissions ? [s.grid({ cols: 2 }, charts(s, cms))] : []),
				s.grid({ cols: 2 }, [
					...(showPages
						? [
								s.section({ title: "Recently updated pages", variant: "card", actions: [s.action("dashboardOpenPages").label("All pages").url(`${prefix}/pages`).link().size("sm")] }, [
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
									{ title: "Latest submissions", variant: "card", actions: [s.action("dashboardOpenSubmissions").label("Inbox").url(`${prefix}/form-submissions`).link().size("sm")] },
									[
										s.list("dashboardLatestSubmissions").items(async () => {
											if (!(await allows(cms, "tbtop-cms.submissions"))) {
												return [];
											}
											const rows = await db.selectFrom("tbtop_form_submissions").selectAll().orderBy("created_at", "desc").orderBy("id", "desc").limit(RECENT).execute();
											return rows.map((row) => {
												const submission = submissionFromRow(row as never);
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
			]);
		},
	});
}

async function stats(s: S, cms: Cms, { showPages, showSubmissions, showDesign }: { showPages: boolean; showSubmissions: boolean; showDesign: boolean }) {
	const db = cms.config.db;
	const window = since(TREND_DAYS);
	// Counts in SQL; only the trend window's rows come back.
	const live = db.selectFrom("tbtop_pages").where("deleted_at", "is", null);
	const byStatus = await live.select(["status", (eb) => eb.fn.countAll().as("n")]).groupBy("status").execute();
	const count = (status: string) => Number(byStatus.find((row) => row.status === status)?.n ?? 0);
	const published = count("published");
	const draft = count("draft");
	const pages = await live.select("created_at").where("created_at", ">=", window).execute();
	const trend = (rows: { created_at: unknown }[]) =>
		daily(
			rows.map((row) => row.created_at),
			TREND_DAYS,
		).map((day) => day.value);
	const tiles = showPages
		? [
				s
					.stat("Pages")
					.value(published + draft)
					.description(`${published} published · ${draft} draft`)
					.icon("file-text")
					.sparkline(trend(pages), "bottom"),
			]
		: [];
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
				.description(`${layouts.length} of 2 slots set`)
				.icon("layout-template")
				.sparkline(trend(layouts), "bottom"),
		);
	}
	return tiles;
}

function charts(s: S, cms: Cms) {
	const db = cms.config.db;
	return [
		s
			.chart("leads-by-status", "donut")
			.set("title", "Leads by status")
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
			.set("title", "Submissions per day")
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
