import { Column, Cond, definePage, Effects, HttpError, type S, Tab } from "@tbtop/admin";
import type { Cms } from "../cms";
import { LanguageError } from "../content/languages";
import { localeName, localeOptions } from "../content/localeCatalog";
import type { Dict } from "../support/values";
import { adminPrefix, listHeader, NAV, siteOf } from "./support";

/** Languages (LanguagesPage): the list with its trash, add, edit, make default, delete, restore. */
export function languagesIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "languages",
		...listHeader("settings", "Languages", "Languages available in the admin panel and on the site."),
		nav: { group: NAV.settings, label: "Languages", icon: "languages", order: 10 },
		view: (s, { request }) => {
			const db = cms.site(request).config.db;
			type Query = ReturnType<typeof languagesQuery>;
			const languagesQuery = () => db.selectFrom("tbtop_languages");
			return s.stack([
				s.row([s.action("new").label("Add language").icon("plus").color("primary").url(`${prefix}/languages/new`)]),
				s
					.table("languages")
					.columns([
						Column.make("name").label("Name").emphasized().searchable(),
						Column.make("locale").label("Locale").searchable(),
						Column.make("is_default").label("Default").boolean(),
						Column.make("is_admin_active").label("Admin").boolean(),
						Column.make("is_frontend_active").label("Site").boolean(),
						Column.make("sort_order").label("Order").number(),
						// Hidden by default: projected so the row actions can tell trashed rows apart.
						Column.make("deleted_at").label("Deleted").datetime().toggleable(true, true),
					])
					.tabs([
						Tab.make("all")
							.label("All")
							.query((qb) => (qb as Query).where("deleted_at", "is", null)),
						Tab.make("deleted")
							.label("Deleted")
							.description("Languages moved to the trash — restore them or delete permanently.")
							.query((qb) => (qb as Query).where("deleted_at", "is not", null)),
					])
					.defaultSort("sort_order", "asc")
					.emptyState("No languages yet", "Add your first language.", "languages")
					.recordUrl((row) => (row.deleted_at === null ? `${prefix}/languages/${String(row.id)}/edit` : null))
					.rowActions([
						s
							.action("makeDefault")
							.label("Make default")
							.hiddenIf(Cond.any(Cond.eq("is_default", true), Cond.notEmpty("deleted_at")))
							.handle(async (ctx) => {
								const row = ctx.row as Dict;
								const site = siteOf(cms, ctx);
								if (await site.languages.setDefault(String(row.locale))) {
									await site.pages.slugs.backfillLocale(String(row.locale));
								}
								return Effects.make().notify("Default language changed").refreshTable("languages");
							}, ["row"]),
						s
							.action("delete")
							.label("Delete")
							.color("danger")
							.confirm(
								"Delete language?",
								"The language will be moved to Deleted and its URLs will stop responding on the site. Translations are kept and come back if you restore it later.",
							)
							.hiddenIf(Cond.any(Cond.eq("is_default", true), Cond.notEmpty("deleted_at")))
							.handle(
								(ctx) =>
									refusing(async () => {
										await siteOf(cms, ctx).languages.delete(Number(ctx.key()));
										return Effects.make().notify("Language deleted").refreshTable("languages");
									}),
								["row"],
							),
						s
							.action("restore")
							.label("Restore")
							.color("gray")
							.hiddenIf(Cond.empty("deleted_at"))
							.handle(
								(ctx) =>
									refusing(async () => {
										const site = siteOf(cms, ctx);
										const restored = await site.languages.restore(Number(ctx.key()));
										if (restored.is_frontend_active) {
											await site.pages.slugs.backfillLocale(restored.locale);
										}
										return Effects.make().notify("Language restored").refreshTable("languages");
									}),
								["row"],
							),
						s
							.action("forceDelete")
							.label("Delete permanently")
							.color("danger")
							.confirm("Delete permanently?", "This cannot be undone.")
							.hiddenIf(Cond.empty("deleted_at"))
							.handle(
								(ctx) =>
									refusing(async () => {
										await siteOf(cms, ctx).languages.forceDelete(Number(ctx.key()));
										return Effects.make().notify("Language deleted permanently").refreshTable("languages");
									}),
								["row"],
							),
					])
					.query(languagesQuery),
			]);
		},
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
	ogLocale: typeof form.og_locale === "string" ? form.og_locale : null,
	sortOrder: Number(form.sort_order ?? 0) || 0,
	isAdminActive: Boolean(form.is_admin_active),
	isFrontendActive: Boolean(form.is_frontend_active),
});

/** Runs a language write, turning a refused one into an error notice. */
async function refusing<T>(write: () => Promise<T>): Promise<T | ReturnType<typeof Effects.make>> {
	try {
		return await write();
	} catch (error) {
		if (error instanceof LanguageError) {
			return Effects.make().notify(error.message, "error");
		}
		throw error;
	}
}

/**
 * Add language (LanguageForm::createFields): the locale is picked from the
 * catalog, minus the live ones (a trashed locale is offered and revived), and
 * the name comes from it; Edit renames it.
 */
export function languageNew(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "languages/new",
		title: "Add language",
		view: async (s, { request }) => {
			const taken = new Set((await cms.site(request).languages.all()).map((row) => row.locale));
			return s
				.form("language", [
					s.section({ title: "Language", columns: 2 }, [
						s
							.select("locale")
							.label("Language")
							.required()
							.searchable()
							.options(localeOptions().filter((option) => !taken.has(option.value))),
						s.number("sort_order").label("Order").rules("nullable|integer|min:0"),
						s.boolean("is_default").label("Default language"),
						s.boolean("is_admin_active").label("Editable in the admin"),
						s.boolean("is_frontend_active").label("Live on the site"),
					]),
					s.actionsRow([s.action("save").label("Add").color("primary").submit(), s.action("cancel").label("Cancel").url(`${prefix}/languages`)]),
				])
				.record({ is_default: false, is_admin_active: true, is_frontend_active: false, sort_order: 0 })
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const form = ctx.form as Dict;
					const locale = String(form.locale ?? "");
					const added = await refusing(async () => {
						if (localeName(locale) === null) {
							throw new LanguageError("Unknown locale — pick a language from the list.");
						}
						const { sortOrder, isAdminActive, isFrontendActive } = values(form);
						// The default is always admin- and site-active (LanguageObserver::saving).
						const isDefault = form.is_default === true;
						return site.languages.create({ locale, sortOrder, isAdminActive: isDefault || isAdminActive, isFrontendActive: isDefault || isFrontendActive });
					});
					if (added instanceof Effects) {
						return added;
					}
					const activated = form.is_default === true && (await site.languages.setDefault(added.locale));
					if (added.is_frontend_active || activated) {
						await site.pages.slugs.backfillLocale(added.locale);
					}
					return `${prefix}/languages`;
				});
		},
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
			const row = await db.selectFrom("tbtop_languages").selectAll().where("id", "=", id).where("deleted_at", "is", null).executeTakeFirst();
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
					const wasFrontend = Boolean(row.is_frontend_active);
					const saved = await refusing(() => site.languages.update(id, values(ctx.form as Dict)));
					if (saved instanceof Effects) {
						return saved;
					}
					if (!wasFrontend && saved.is_frontend_active) {
						await site.pages.slugs.backfillLocale(row.locale);
					}
					return Effects.make().notify("Saved").redirect(`${prefix}/languages`);
				});
		},
	});
}
