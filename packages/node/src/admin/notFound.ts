import { type ActionCtx, Column, definePage, Effects, type S, Tab } from "@tbtop/admin";
import type { SelectQueryBuilder } from "kysely";
import type { Cms } from "../cms";
import { RedirectError } from "../content/redirects";
import type { CmsDatabase } from "../db/schema";
import { flag, isDict } from "../support/values";
import { listHeader, NAV, siteOf } from "./support";

type HitsQuery = SelectQueryBuilder<CmsDatabase, "tbtop_not_found_hits", object>;

const ALL_LOCALES = "*";

const keys = (ctx: ActionCtx): number[] => ctx.selection.map(Number);

/**
 * NotFoundLogPage: the 404 log, next to redirects and under the same
 * ability, since the fix for a logged miss is nearly always a redirect.
 */
export function notFoundLog(cms: Cms) {
	return definePage({
		path: "not-found",
		...listHeader("content", "404 log", "Paths visitors requested that resolved to nothing — no page, no redirect."),
		nav: { group: NAV.content, label: "404 log", icon: "file-question", order: 30 },
		view: async (s, { request }) => {
			const site = cms.site(request);
			const db = cms.config.db;
			// RedirectResolver::match: a rule for the hit's locale or for every locale.
			const rules = await db.selectFrom("tbtop_redirects").select(["locale", "from_path"]).execute();
			const redirected = new Set(rules.map((rule) => `${rule.locale ?? ALL_LOCALES}|${rule.from_path}`));
			const hasRedirect = (row: Record<string, unknown>) =>
				redirected.has(`${String(row.locale)}|${String(row.path)}`) || redirected.has(`${ALL_LOCALES}|${String(row.path)}`);
			const locales = (await site.languages.frontendActive()).map((row) => ({ value: row.locale, label: row.name }));
			return s.stack([
				s
					.table("notFoundHits")
					.columns([
						Column.make("path").label("Path").searchable().emphasized(),
						Column.make("locale").label("Language"),
						Column.make("hits").label("Hits").sortable().align("right"),
						Column.make("last_seen_at").label("Last seen").datetime().sortable(),
						Column.make("last_referer").label("Last referer").toggleable(true, true).muted(),
						Column.make("last_user_agent").label("Last user agent").toggleable(true, true).muted(),
						// Never shown: projected so the row actions' hiddenIf can read them.
						Column.make("has_redirect")
							.label("Redirected")
							.formatUsing((_, row) => (hasRedirect(row as Record<string, unknown>) ? "yes" : "no"))
							.toggleable(false, true),
						Column.make("ignored")
							.label("Ignored")
							.formatUsing((_, row) => (Number((row as Record<string, unknown>).is_ignored) === 1 || (row as Record<string, unknown>).is_ignored === true ? "yes" : "no"))
							.toggleable(false, true),
					])
					.tabs([
						Tab.make("active")
							.label("Active")
							.count()
							.query((qb) => (qb as HitsQuery).where("is_ignored", "=", flag(false))),
						Tab.make("ignored")
							.label("Ignored")
							.count()
							.query((qb) => (qb as HitsQuery).where("is_ignored", "=", flag(true))),
					])
					.defaultSort("last_seen_at", "desc")
					.paginate(25, [25, 50, 100])
					.emptyState("No 404s logged", "Misses on the site are counted here, scanner probes left out.", "file-question")
					.rowActions([
						createRedirect(s, cms, locales),
						ignoreAction(s, cms, "ignore", "Ignore", true).hiddenIf("ignored", "=", "yes"),
						ignoreAction(s, cms, "unignore", "Stop ignoring", false).hiddenIf("ignored", "!=", "yes"),
						s
							.action("deleteHit")
							.label("Delete")
							.color("danger")
							.confirm("Delete this entry?", "It is counted again from one on the next miss.")
							.handle(async (ctx) => {
								await db.deleteFrom("tbtop_not_found_hits").where("id", "=", Number(ctx.key())).execute();
								return Effects.make().notify("Deleted").refreshTable("notFoundHits");
							}, ["row"]),
					])
					.bulkActions([
						s
							.action("ignoreSelected")
							.label("Ignore")
							.handle(async (ctx) => {
								await db.updateTable("tbtop_not_found_hits").set({ is_ignored: flag(true) }).where("id", "in", keys(ctx)).execute();
								return Effects.make().notify("Ignored").refreshTable("notFoundHits");
							}, ["selection"]),
						s
							.action("deleteSelected")
							.label("Delete")
							.color("danger")
							.confirm("Delete the selected entries?")
							.handle(async (ctx) => {
								await db.deleteFrom("tbtop_not_found_hits").where("id", "in", keys(ctx)).execute();
								return Effects.make().notify("Deleted").refreshTable("notFoundHits");
							}, ["selection"]),
					])
					.query(() => db.selectFrom("tbtop_not_found_hits")),
			]);
		},
	});
}

function ignoreAction(s: S, cms: Cms, name: string, label: string, ignored: boolean) {
	return s.action(name).label(label).handle(async (ctx) => {
		await cms.config.db.updateTable("tbtop_not_found_hits").set({ is_ignored: flag(ignored) }).where("id", "=", Number(ctx.key())).execute();
		return Effects.make().notify(ignored ? "Ignored" : "No longer ignored").refreshTable("notFoundHits");
	}, ["row"]);
}

/** The miss's path and locale come from the row, not the form; the save goes through Redirects.create, as the redirects page's own. */
function createRedirect(s: S, cms: Cms, locales: { value: string; label: string }[]) {
	const save = s
		.action("notFoundCreateRedirectSave")
		.label("Create redirect")
		.color("primary")
		.handle(async (ctx) => {
			const form = isDict(ctx.form) ? ctx.form : {};
			const locale = String(form.locale ?? ctx.row.locale ?? "");
			try {
				await siteOf(cms, ctx).redirects.create({
					fromPath: String(ctx.row.path ?? ""),
					toPath: String(form.to_path ?? ""),
					statusCode: Number(form.status_code ?? 301),
					locale: locale === "" || locale === ALL_LOCALES ? null : locale,
				});
			} catch (error) {
				if (error instanceof RedirectError) {
					return Effects.make().haltModal(error.message);
				}
				throw error;
			}
			return Effects.make().notify("Redirect created").closeModal().refreshTable("notFoundHits");
		}, ["row", "form"]);
	const form = s.form("notFoundCreateRedirect", [
		s.text("from_path").label("From path").meta("disabled", true),
		s.select("locale").label("Language").options([{ value: ALL_LOCALES, label: "Every language" }, ...locales]),
		s.text("to_path").label("To path or URL").required().rules("max:2048"),
		s
			.select("status_code")
			.label("Status")
			.required()
			.options([
				{ value: "301", label: "301 Moved permanently" },
				{ value: "302", label: "302 Found" },
			]),
		s.actionsRow([save]),
	]);
	return s
		.action("notFoundCreateRedirect")
		.label("Create redirect")
		.query((ctx) => ({ from_path: String(ctx.row.path ?? ""), locale: String(ctx.row.locale ?? ALL_LOCALES), to_path: "", status_code: "301" }), ["row"])
		.modal("Redirect this path", form)
		.hiddenIf("has_redirect", "=", "yes");
}
