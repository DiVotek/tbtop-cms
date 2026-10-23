import { firstText, type Page, pageFromRow } from "../content/page";
import { whereLive } from "../content/pages";
import { ancestorCrumbs } from "../frontend/breadcrumbs";
import type { Site } from "../site";
import { decodeJson, isDict, sqlDate } from "../support/values";
import { renderedDescription, renderedTitle } from "./resolver";
import { ownDescription, ownTitle } from "./schemaContext";

export type SeoIssueKey =
	| "missing_title"
	| "missing_description"
	| "missing_translation"
	| "duplicate_title"
	| "duplicate_description"
	| "missing_og_image"
	| "noindex";

export type SeoIssue = {
	severity: "fail" | "warn";
	key: SeoIssueKey;
	/** The issue's label. */
	issue: string;
	pageId: number;
	/** The page's label in the issue's locale (the default one for a locale-free issue). */
	page: string;
	/** "" for a check that does not depend on the locale. */
	locale: string;
	detail: string;
};

export type SeoAuditResult = { issues: SeoIssue[]; pagesScanned: number };

export type SeoSnapshot = { date: string; pagesScanned: number; pagesWithIssues: number; failCount: number; warnCount: number; issueCounts: Record<string, number> };

export const SEO_ISSUE_LABELS: Record<SeoIssueKey, string> = {
	missing_title: "Missing title",
	missing_description: "Missing description",
	missing_translation: "Missing translation",
	duplicate_title: "Duplicate title",
	duplicate_description: "Duplicate description",
	missing_og_image: "Missing OG image",
	noindex: "Noindex",
};

const DETAILS: Record<Exclude<SeoIssueKey, "duplicate_title" | "duplicate_description">, string> = {
	missing_title: "Neither an SEO title nor the page's own title/name is set for this locale.",
	missing_description: "Neither an SEO description nor the page's own summary is set for this locale.",
	missing_translation: "No title and no name set for this locale — a hreflang gap for active crawlers.",
	missing_og_image: "No OG image resolves for this page — not on the page, and no site-wide default is configured.",
	noindex: "This published page is set to noindex. Shown here for visibility — it may be intentional.",
};

const own = (value: unknown): boolean => typeof value === "string" && value.trim() !== "";

/**
 * SeoAudit: every live page that has a URL (error pages left out, as in the
 * sitemap) against every frontend locale, from one page query. Fails sort
 * first, then by page label, issue and locale.
 */
export async function runSeoAudit(site: Site): Promise<SeoAuditResult> {
	const pages = (await whereLive(site.pages.query()).selectAll("tbtop_pages").where("error_class", "is", null).orderBy("id").execute()).map(pageFromRow);
	const locales = (await site.languages.frontendActive()).map((row) => row.locale);
	const fallback = await site.languages.defaultLocale();
	const label = (page: Page, locale: string) => firstText(page, ["title", "name"], locale, fallback) || `Untitled page #${page.id}`;
	const issue = (severity: SeoIssue["severity"], key: SeoIssueKey, page: Page, locale: string, detail: string): SeoIssue => ({
		severity,
		key,
		issue: SEO_ISSUE_LABELS[key],
		pageId: page.id,
		page: label(page, locale || fallback),
		locale,
		detail,
	});
	const issues: SeoIssue[] = [];
	for (const page of pages) {
		for (const locale of locales) {
			if ((await ownTitle(site, page, locale)) === "") {
				issues.push(issue("fail", "missing_title", page, locale, DETAILS.missing_title));
			}
			if ((await ownDescription(site, page, locale)) === "") {
				issues.push(issue("fail", "missing_description", page, locale, DETAILS.missing_description));
			}
			// This locale's own words: a fallback value is another locale's content.
			if (!own(page.title[locale]) && !own(page.name[locale])) {
				issues.push(issue("warn", "missing_translation", page, locale, DETAILS.missing_translation));
			}
		}
	}
	for (const locale of locales) {
		const byTitle = new Map<string, Page[]>();
		const byDescription = new Map<string, Page[]>();
		for (const page of pages) {
			const ancestors = () => ancestorCrumbs(site, page, locale);
			const title = await renderedTitle(site, page, locale, ancestors);
			const description = await renderedDescription(site, page, locale, ancestors);
			if (title !== "") {
				byTitle.set(title, [...(byTitle.get(title) ?? []), page]);
			}
			if (description !== "") {
				byDescription.set(description, [...(byDescription.get(description) ?? []), page]);
			}
		}
		for (const [key, groups, noun] of [
			["duplicate_title", byTitle, "title"],
			["duplicate_description", byDescription, "description"],
		] as const) {
			for (const group of groups.values()) {
				for (const page of group.length < 2 ? [] : group) {
					const others = group.filter((other) => other.id !== page.id).map((other) => label(other, locale));
					issues.push(issue("warn", key, page, locale, `Same ${noun} as: ${others.join(", ")}.`));
				}
			}
		}
	}
	const siteImage = await site.settings.get("seo.default_og_image");
	for (const page of pages) {
		// A page that names an image made its choice, even a broken one: the card then shows none, not the default.
		const override = page.seo.og_image;
		const named = own(override) || own(page.image);
		const ref = named ? (own(override) ? override : page.image) : siteImage;
		const url = typeof ref === "string" && ref !== "" ? site.mediaUrl(ref) : null;
		if ((url ?? "").trim() === "") {
			issues.push(issue("warn", "missing_og_image", page, "", DETAILS.missing_og_image));
		}
		if (!page.isIndexable) {
			issues.push(issue("warn", "noindex", page, "", DETAILS.noindex));
		}
	}
	const sortKey = (row: SeoIssue) => `${row.severity === "fail" ? "0" : "1"}-${row.page}-${row.key}-${row.locale}`;
	issues.sort((a, b) => (sortKey(a) < sortKey(b) ? -1 : sortKey(a) > sortKey(b) ? 1 : 0));
	return { issues, pagesScanned: pages.length };
}

/** SeoAuditSnapshotWriter: today's (UTC) counts, overwriting an earlier run of the same day. */
export async function writeSeoSnapshot(site: Site, result: SeoAuditResult, now = new Date()): Promise<void> {
	const db = site.config.db;
	const counts: Record<string, number> = {};
	for (const row of result.issues) {
		counts[row.key] = (counts[row.key] ?? 0) + 1;
	}
	const fail = result.issues.filter((row) => row.severity === "fail").length;
	const values = {
		pages_scanned: result.pagesScanned,
		pages_with_issues: new Set(result.issues.map((row) => row.pageId)).size,
		fail_count: fail,
		warn_count: result.issues.length - fail,
		issue_counts: JSON.stringify(counts),
		updated_at: sqlDate(now),
	};
	const day = sqlDate(now).slice(0, 10);
	const updated = await db.updateTable("tbtop_seo_audit_snapshots").set(values).where("captured_on", "=", day).executeTakeFirst();
	if (Number(updated.numUpdatedRows) > 0) {
		return;
	}
	try {
		await db.insertInto("tbtop_seo_audit_snapshots").values({ ...values, captured_on: day, created_at: sqlDate(now) }).execute();
	} catch {
		// Another request wrote today's row first: overwrite it.
		await db.updateTable("tbtop_seo_audit_snapshots").set(values).where("captured_on", "=", day).execute();
	}
}

/** A DATE column read by pg or mysql2: local midnight of the stored day. */
const localDay = (date: Date): string =>
	`${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;

/** Snapshots from the last `days` days (default 90), oldest first. */
export async function seoSnapshots(site: Site, days = 90, now = new Date()): Promise<SeoSnapshot[]> {
	const from = sqlDate(new Date(now.getTime() - (days - 1) * 86_400_000)).slice(0, 10);
	const rows = await site.config.db.selectFrom("tbtop_seo_audit_snapshots").selectAll().where("captured_on", ">=", from).orderBy("captured_on").execute();
	return rows.map((row) => {
		const counts = decodeJson(row.issue_counts);
		const captured = row.captured_on as unknown;
		return {
			date: captured instanceof Date ? localDay(captured) : String(captured).slice(0, 10),
			pagesScanned: Number(row.pages_scanned),
			pagesWithIssues: Number(row.pages_with_issues),
			failCount: Number(row.fail_count),
			warnCount: Number(row.warn_count),
			issueCounts: isDict(counts) ? (counts as Record<string, number>) : {},
		};
	});
}
