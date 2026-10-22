import { Column, definePage, Effects, HttpError, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { insertId } from "../support/db";
import { type Dict, flag, sqlDate } from "../support/values";
import { adminPrefix, NAV, siteOf } from "./support";

/** Languages (LanguagesPage): the list, add, edit, make default. */
export function languagesIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "languages",
		title: "Languages",
		nav: { group: NAV.settings, label: "Languages", icon: "languages", order: 10 },
		view: (s, { request }) =>
			s.stack([
				s.row([s.action("new").label("Add language").icon("plus").color("primary").url(`${prefix}/languages/new`)]),
				s
					.table("languages")
					.columns([
						Column.make("name").label("Name").emphasized(),
						Column.make("locale").label("Locale"),
						Column.make("is_default").label("Default").boolean(),
						Column.make("is_admin_active").label("Admin").boolean(),
						Column.make("is_frontend_active").label("Site").boolean(),
						Column.make("sort_order").label("Order").number(),
					])
					.defaultSort("sort_order", "asc")
					.recordUrl((row) => `${prefix}/languages/${String(row.id)}/edit`)
					.rowActions([
						s
							.action("makeDefault")
							.label("Make default")
							.handle(async (ctx) => {
								const row = ctx.row as Dict;
								await siteOf(cms, ctx).languages.setDefault(String(row.locale));
								return Effects.make().notify("Default language changed").refreshTable("languages");
							}, ["row"]),
					])
					.query(() => cms.site(request).config.db.selectFrom("tbtop_languages").where("deleted_at", "is", null)),
			]),
	});
}

function languageForm(s: S) {
	return [
		s.section({ title: "Language", columns: 2 }, [
			s.text("name").label("Name").required().rules("max:255"),
			s.text("locale").label("Locale").required().regex("/^[a-z]{2}(-[A-Z]{2})?$/").rules("max:5"),
			s.text("og_locale").label("Open Graph locale").placeholder("uk_UA").rules("nullable|max:10"),
			s.number("sort_order").label("Order").rules("nullable|integer|min:0"),
			s.boolean("is_admin_active").label("Editable in the admin"),
			s.boolean("is_frontend_active").label("Live on the site"),
		]),
	];
}

const values = (form: Dict) => ({
	name: String(form.name ?? ""),
	locale: String(form.locale ?? ""),
	og_locale: typeof form.og_locale === "string" && form.og_locale !== "" ? form.og_locale : null,
	sort_order: Number(form.sort_order ?? 0) || 0,
	is_admin_active: flag(Boolean(form.is_admin_active)),
	is_frontend_active: flag(Boolean(form.is_frontend_active)),
});

export function languageNew(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "languages/new",
		title: "Add language",
		view: (s) =>
			s
				.form("language", [
					...languageForm(s),
					s.actionsRow([s.action("save").label("Add").color("primary").submit(), s.action("cancel").label("Cancel").url(`${prefix}/languages`)]),
				])
				.record({ is_admin_active: true, is_frontend_active: false, sort_order: 0 })
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const row = values(ctx.form as Dict);
					if ((await site.languages.find(row.locale)) !== null) {
						return Effects.make().notify(`Language ${row.locale} already exists.`, "error");
					}
					const now = sqlDate(new Date());
					const isFirst = (await site.languages.all()).length === 0;
					await insertId(
						site.config.db,
						site.config.db.insertInto("tbtop_languages").values({ ...row, is_default: flag(isFirst), created_at: now, updated_at: now }),
					);
					site.languages.forget();
					if (row.is_frontend_active) {
						await site.pages.slugs.backfillLocale(row.locale);
					}
					return `${prefix}/languages`;
				}),
	});
}

export function languageEdit(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "languages/$language/edit",
		title: "Edit language",
		view: async (s, { params, request }) => {
			const db = cms.site(request).config.db;
			const id = Number(params.language);
			const row = await db.selectFrom("tbtop_languages").selectAll().where("id", "=", id).executeTakeFirst();
			if (row === undefined) {
				throw new HttpError(404, "Language not found.");
			}
			const isDefault = Boolean(row.is_default);
			return s
				.form("language", [
					...languageForm(s),
					s.actionsRow([s.action("save").label("Save").color("primary").submit(), s.action("cancel").label("Back").url(`${prefix}/languages`)]),
				])
				.record({ ...row, is_default: isDefault, is_admin_active: Boolean(row.is_admin_active), is_frontend_active: Boolean(row.is_frontend_active) })
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const next = values(ctx.form as Dict);
					const wasFrontend = Boolean(row.is_frontend_active);
					// The default language is always admin- and frontend-active.
					await db
						.updateTable("tbtop_languages")
						.set({
							...next,
							locale: row.locale,
							...(isDefault ? { is_admin_active: 1 as const, is_frontend_active: 1 as const } : {}),
							updated_at: sqlDate(new Date()),
						})
						.where("id", "=", id)
						.execute();
					site.languages.forget();
					if (!wasFrontend && (next.is_frontend_active === 1 || isDefault)) {
						await site.pages.slugs.backfillLocale(row.locale);
					}
					return Effects.make().notify("Saved").redirect(`${prefix}/languages`);
				});
		},
	});
}
