import { type ActionCtx, Column, Cond, definePage, Effects, type S, Tab } from "@tbtop/admin";
import { type SelectQueryBuilder, sql } from "kysely";
import type { Cms } from "../cms";
import type { CmsDatabase } from "../db/schema";
import { firstText, type Page } from "../content/page";
import { PageRuleError } from "../content/pageRules";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";
import { dialectOfDb } from "../support/db";
import { formParentId, formSubtype, resolveLeafType, subtypeOptions } from "./pageLeafType";
import { draftPage, templateContent } from "../content/pageTemplate";
import { adminPrefix, listHeader, NAV, siteOf } from "./support";

export const STATUS = [
	{ value: "draft", label: "Draft" },
	{ value: "published", label: "Published" },
];

const typeLabel = (cms: Cms, key: string) => (cms.pageTypes.has(key) ? cms.pageTypes.get(key).label : key);

const DISPLAY_STATES: Record<string, { label: string; color: string }> = {
	published: { label: "Published", color: "success" },
	draft: { label: "Draft", color: "warning" },
	scheduled: { label: "Scheduled", color: "gray" },
	expired: { label: "Expired", color: "gray" },
};

const SEO_STATES = { fail: { label: "Missing", color: "danger" }, warn: { label: "Needs work", color: "warning" }, pass: { label: "Good", color: "success" } } as const;

const badges = (states: Record<string, { label: string; color: string }>) => Object.fromEntries(Object.values(states).map((state) => [state.label, { color: state.color }]));

/**
 * A translatable column with no non-empty string in any locale. Whitespace
 * counts as content, as in emptyTranslatable(): the badge and its tooltip
 * read the same facts the SEO filter's SQL does.
 */
const blank = (raw: unknown): boolean => {
	const value = decodeJson(raw);
	return !(isDict(value) ? Object.values(value) : []).some((text) => typeof text === "string" && text !== "");
};

/** PageListState::seoTooltip: why the badge says what seo_state says; mirrors seoStateSql arm for arm. */
function seoReasons(row: Dict): string[] {
	return [...(blank(row.name) ? ["No name"] : []), ...(blank(row.summary) ? ["No summary"] : []), ...(!truthy(row.is_indexable) ? ["Excluded from indexing"] : [])];
}

/**
 * PageListState::emptyTranslatableSql: true when a JSON translatable column
 * holds no non-empty string in any locale, spelled per dialect. A column that
 * is not a locale map (null, a bare string) holds no translation, on every dialect.
 */
function emptyTranslatable(db: { getExecutor(): { adapter: object } }, column: "name" | "summary") {
	const col = sql.ref(`tbtop_pages.${column}`);
	switch (dialectOfDb(db)) {
		case "postgres":
			return sql`not jsonb_path_exists(coalesce(${col}::jsonb, 'null'::jsonb), 'lax $.* ? (@.type() == "string" && @ <> "")')`;
		case "mysql":
			return sql`(${col} is null or json_type(${col}) <> 'OBJECT' or json_search(${col}, 'one', '_%') is null)`;
		default:
			return sql`(json_type(coalesce(${col}, '{}')) <> 'object' or not exists (select 1 from json_each(${col}) where type = 'text' and value <> ''))`;
	}
}

/** PageListState::seoStateSql: the SEO badge's state, in SQL so the SEO filter matches what each row shows. */
function seoStateSql(db: { getExecutor(): { adapter: object } }) {
	const [name, summary] = (["name", "summary"] as const).map((column) => emptyTranslatable(db, column));
	return sql<string>`case when ${name} then 'fail' when ${summary} or not tbtop_pages.is_indexable then 'warn' else 'pass' end`;
}

const displayStateSql = (now: string) =>
	sql<string>`case when tbtop_pages.status <> 'published' then 'draft' when tbtop_pages.published_at is not null and tbtop_pages.published_at > ${now} then 'scheduled' when tbtop_pages.unpublish_at is not null and tbtop_pages.unpublish_at <= ${now} then 'expired' else 'published' end`;

const stateOptions = (states: Record<string, { label: string }>): Option[] => Object.entries(states).map(([value, state]) => ({ value, label: state.label }));

const truthy = (value: unknown): boolean => value === true || value === 1 || value === "1" || value === "t" || value === "true";

/** The categories of the create form's parent select, from the view to the header (built right after it, on the same request). */
const createParents = new WeakMap<Request, Option[]>();

/**
 * Pages list (PagesPage): New page in the header; a tab per type with live
 * pages and Deleted, each counted; search on the name; the display state
 * and an SEO badge; view on site and duplicate on a live row, restore and
 * delete permanently on a trashed one. Deleting a live page is the editor's.
 */
export function pagesIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "pages",
		...listHeader("content", "Pages"),
		nav: { group: NAV.content, label: "Pages", icon: "files", order: 10 },
		headerActions: (s, { request }) => [createAction(s, cms, createParents.get(request) ?? [])],
		view: async (s, { request }) => {
			const site = cms.site(request);
			const db = site.config.db;
			const locale = await site.languages.defaultLocale();
			const all = await db.selectFrom("tbtop_pages").select(["id", "name"]).execute();
			const names = new Map(all.map((row) => [Number(row.id), row.name]));
			const categories = await site.pages.query().select(["id", "name"]).where("type", "=", "category").orderBy("sort_order").orderBy("id").execute();
			createParents.set(request, parentOptions(categories, locale));
			// Only a page with a child is a useful parent to filter by (PagesPage::parentOptions).
			const parents = await site.pages
				.query()
				.select(["id", "name"])
				.where((eb) => eb.exists(eb.selectFrom("tbtop_pages as child").select("child.id").whereRef("child.parent_id", "=", "tbtop_pages.id").where("child.deleted_at", "is", null)))
				.orderBy("sort_order")
				.orderBy("id")
				.execute();
			const present = (await site.pages.query().select("type").distinct().execute()).map((row) => row.type);
			const keys = cms.pageTypes.bases().map((type) => type.key);
			const tabTypes = [...keys.filter((key) => present.includes(key)), ...present.filter((key) => !keys.includes(key)).sort()];
			const live = (qb: unknown) => (qb as PagesTable).where("tbtop_pages.deleted_at", "is", null);
			// Types with listedInAll: false (tags) stay off "All"; an unlisted subtype gets a tab of its own.
			const unlisted = cms.pageTypes.unlistedInAll();
			// A row's own type decides: its subtype when registered, else its base (a listed subtype of an unlisted base stays).
			const listedKeys = cms.pageTypes
				.all()
				.map((type) => type.key)
				.filter((key) => !unlisted.includes(key));
			const listed = (qb: unknown) =>
				unlisted.length === 0
					? live(qb)
					: live(qb)
							.where((eb) => eb.or([eb("tbtop_pages.subtype", "is", null), eb("tbtop_pages.subtype", "not in", unlisted)]))
							.where((eb) =>
								eb.or([
									eb("tbtop_pages.type", "not in", unlisted),
									...(listedKeys.length === 0 ? [] : [eb("tbtop_pages.subtype", "in", listedKeys)]),
								]),
							);
			// A base type's tab leaves its unlisted subtypes to their own tab, as "All" does.
			const listedSubtypes = (qb: unknown) =>
				unlisted.length === 0 ? live(qb) : live(qb).where((eb) => eb.or([eb("tbtop_pages.subtype", "is", null), eb("tbtop_pages.subtype", "not in", unlisted)]));
			const presentSubtypes = (await site.pages.query().select("subtype").distinct().where("subtype", "is not", null).execute()).map((row) => row.subtype);
			const subtypeTabs = unlisted.filter((key) => cms.pageTypes.get(key).base !== key && presentSubtypes.includes(key));
			const now = sqlDate(new Date());
			const tab = (name: string, label: string) => Tab.make(name).label(label).count().description(`${label} — categories group content, pages stand alone, articles live under a category.`);
			return s.stack([
				s
					.table("pages")
					.columns([
						Column.make("name").label("Title").translatable().emphasized(),
						Column.make("type")
							.label("Type")
							.muted()
							.uppercase()
							.formatUsing((value) => typeLabel(cms, String(value))),
						// Only once a host registers a subtype: before that every row would be blank (PagesPage::columns).
						...(cms.pageTypes.all().some((type) => type.base !== type.key)
							? [
									Column.make("subtype")
										.label("Subtype")
										.muted()
										.uppercase()
										.formatUsing((value) => (typeof value === "string" && value !== "" ? typeLabel(cms, value) : "")),
								]
							: []),
						Column.make("parent_id")
							.label("Parent")
							.muted()
							.formatUsing((value) => (value === null ? "" : firstText(pageLabel({ name: (names.get(Number(value)) as string | null) ?? null }), ["name"], locale, locale))),
						Column.make("display_state")
							.label("Status")
							.formatUsing((value) => DISPLAY_STATES[String(value)]?.label ?? String(value))
							.badge(badges(DISPLAY_STATES)),
						Column.make("seo_state")
							.label("SEO")
							.formatUsing((value) => SEO_STATES[String(value) as keyof typeof SEO_STATES]?.label ?? String(value))
							.tooltip((row) => seoReasons(row as Dict).join("\n"))
							.badge(badges(SEO_STATES))
							.toggleable(),
						Column.make("updated_at").label("Updated").date("M j, Y").muted().sortable(),
						// Projected for the row actions' conditions and the view link, not shown.
						Column.make("deleted_at").label("Deleted").date("M j, Y").toggleable(true, true),
						Column.make("path").label("Path").muted().toggleable(true, true),
					])
					.tabs([
						tab("all", "All").query(listed),
						...tabTypes.map((type) => tab(type, typeLabel(cms, type)).query((qb) => listedSubtypes(qb).where("tbtop_pages.type", "=", type))),
						...subtypeTabs.map((key) => tab(key, typeLabel(cms, key)).query((qb) => live(qb).where("tbtop_pages.subtype", "=", key))),
						Tab.make("deleted")
							.label("Deleted")
							.count()
							.description("Pages moved to the trash — restore them or delete permanently.")
							.query((qb) => (qb as PagesTable).where("tbtop_pages.deleted_at", "is not", null)),
					])
					.filters([
						// The derived table below projects display_state and seo_state, so a filter compares against the badges' own values.
						s
							.select("parent_id")
							.label("Parent")
							.options(parentOptions(parents, locale))
							.filterUsing((qb, value) => (qb as PagesTable).where("tbtop_pages.parent_id", "=", Number(value))),
						s
							.select("display_state")
							.label("Status")
							.options(stateOptions(DISPLAY_STATES))
							.filterUsing((qb, value) => (qb as PagesTable).where(sql.ref("tbtop_pages.display_state"), "=", String(value))),
						s
							.select("seo_state")
							.label("SEO")
							.options(stateOptions(SEO_STATES))
							.filterUsing((qb, value) => (qb as PagesTable).where(sql.ref("tbtop_pages.seo_state"), "=", String(value))),
					])
					.searchable(["tbtop_pages.name_text"])
					.defaultSort("id", "asc")
					.paginate(25, [25, 50, 100])
					.emptyState("Nothing here yet", "Create your first page to get started.", "files")
					// No edit page for a trashed row: it is not clickable.
					.recordUrl((row) => (row.deleted_at === null || row.deleted_at === undefined ? `${prefix}/pages/${String(row.id)}/edit` : null))
					.rowActions(rowActions(s, cms))
					// A derived table under the same alias holds every computed column (the JSON name's text twin
					// that LIKE accepts on Postgres, the path, the display state): the panel adds only `*` on top,
					// and MySQL refuses a bare `*` after other select items.
					.query(() =>
						db.selectFrom(
							db
								.selectFrom("tbtop_pages")
								.selectAll()
								.select((eb) => [
									sql<string>`cast(tbtop_pages.name as ${sql.raw(textType(db))})`.as("name_text"),
									eb.selectFrom("tbtop_page_slugs").select("tbtop_page_slugs.path").whereRef("tbtop_page_slugs.page_id", "=", "tbtop_pages.id").where("tbtop_page_slugs.locale", "=", locale).limit(1).as("path"),
									displayStateSql(now).as("display_state"),
									seoStateSql(db).as("seo_state"),
								])
								.as("tbtop_pages"),
						),
					),
			]);
		},
	});
}

type PagesTable = SelectQueryBuilder<CmsDatabase, "tbtop_pages", object>;

/** MySQL casts to CHAR, not TEXT. */
function textType(db: { getExecutor(): { adapter: object } }): string {
	return dialectOfDb(db) === "mysql" ? "char" : "text";
}

/** PagesRowActions: view on site and duplicate for a live row, restore and delete permanently for a trashed one. */
function rowActions(s: S, cms: Cms) {
	const refused = (error: unknown) => {
		if (error instanceof PageRuleError) {
			return Effects.make().notify(error.message, "error");
		}
		throw error;
	};
	return [
		// An error page has no path, and a trashed one lost its slugs: no link for either.
		s.action("pageView").label("").link().icon("external-link").tooltip("View on site").url("/{row.path}").openUrlInNewTab().hiddenIf(Cond.any(Cond.notEmpty("deleted_at"), Cond.eq("path", null))),
		s
			.action("pageDuplicate")
			.label("Duplicate")
			.handle(async (ctx) => {
				try {
					await siteOf(cms, ctx).pages.duplicate(Number(ctx.key()), { by: userKey(ctx) });
				} catch (error) {
					return refused(error);
				}
				return Effects.make().notify("Page duplicated").refreshTable("pages");
			}, ["row"])
			// A copy of a source-backed page would be a second page of one source.
			.hiddenIf(Cond.any(Cond.notEmpty("deleted_at"), Cond.notEmpty("source_id"))),
		s
			.action("pageRestore")
			.label("Restore")
			.color("gray")
			.handle(async (ctx) => {
				try {
					await siteOf(cms, ctx).pages.untrash(Number(ctx.key()));
				} catch (error) {
					return refused(error);
				}
				return Effects.make().notify("Page restored").refreshTable("pages");
			}, ["row"])
			.hiddenIf(Cond.empty("deleted_at")),
		s
			.action("pageForceDelete")
			.label("Delete permanently")
			.color("danger")
			.confirm("Delete permanently?", "This cannot be undone.")
			.handle(async (ctx) => {
				try {
					await siteOf(cms, ctx).pages.forceDelete(Number(ctx.key()));
				} catch (error) {
					return refused(error);
				}
				return Effects.make().notify("Page deleted permanently").refreshTable("pages");
			}, ["row"])
			.hiddenIf(Cond.empty("deleted_at")),
	];
}

function createAction(s: S, cms: Cms, categories: Option[]) {
	const prefix = adminPrefix(cms);
	// A source-backed type's pages are made by code, never here.
	const bases = cms.pageTypes.creatable();
	const types = bases.map((type) => ({ value: type.key, label: type.label }));
	// A parent is always a category: an article's (required while the article type requires one), a subcategory's optional one.
	const parent = s.select("parent_id").label("Category").options(categories).hiddenIf(Cond.notIn("type", ["category", "article"]));
	if (cms.pageTypes.has("article") && cms.pageTypes.get("article").requiresParent) {
		parent.requiredIf(Cond.eq("type", "article"));
	}
	// One subtype select per base that has subtypes, shown only for its base (PageForm::subtypeField).
	const subtypes = bases.flatMap((type) => {
		const options = subtypeOptions(cms.pageTypes, type.key);
		return options.length === 0 ? [] : [s.select(`subtype_${type.key}`).label("Subtype").options(options).default("").hiddenIf(Cond.neq("type", type.key))];
	});
	return s
		.action("pageCreate")
		.label("New page")
		.icon("plus")
		.color("primary")
		.modal(
			"New page",
			s
				.form("pageCreate", [
					s.text("name").label("Name").required().translatable().rules("max:255"),
					s.select("type").label("Type").options(types).required().default("page"),
					...subtypes,
					parent,
					s.flex([s.spacer(), s.action("pageCreateCancel").label("Cancel").handle(() => Effects.make().closeModal()), s.action("pageCreateSave").label("Create").color("primary").submit()], { justify: "end" }),
				])
				.onSubmit(async (ctx) => {
					const form = ctx.form as Dict;
					const type = String(form.type ?? "page");
					if (!cms.pageTypes.has(type) || cms.pageTypes.get(type).sourceBacked) {
						return Effects.make().notify("Unknown page type.", "error");
					}
					try {
						const tree = await resolveLeafType(siteOf(cms, ctx), cms.pageTypes, null, type, formParentId(form.parent_id));
						const page = await createPage(cms, ctx, { ...tree, subtype: formSubtype(form[`subtype_${type}`]) });
						return `${prefix}/pages/${page.id}/edit`;
					} catch (error) {
						if (error instanceof PageRuleError) {
							return Effects.make().notify(error.message, "error");
						}
						throw error;
					}
				}),
		);
}

export type Option = { value: string; label: string };

/** Parent select options: each page's name in `locale`, or its id. */
export function parentOptions(rows: { id: number; name: string | null }[], locale: string): Option[] {
	return rows.map((row) => ({ value: String(row.id), label: firstText(pageLabel(row), ["name"], locale, locale) || `#${row.id}` }));
}

/** PageFormWriter::save on create: the content starts from the type's template. */
async function createPage(cms: Cms, ctx: ActionCtx, tree: { type: string; parentId: number | null; subtype: string | null }): Promise<Page> {
	const form = ctx.form as Dict;
	const name = isDict(form.name) ? form.name : {};
	const content = templateContent(cms.pageTypes, draftPage({ ...tree, name }));
	return siteOf(cms, ctx).pages.create({ ...tree, name, content }, { by: userKey(ctx) });
}


export const userKey = (ctx: ActionCtx): string | number | null => {
	const user = ctx.user as { id?: unknown } | null;
	return typeof user?.id === "number" || typeof user?.id === "string" ? user.id : null;
};

const pageLabel = (row: { name: string | null }): Page => ({ name: safeJson(row.name) }) as unknown as Page;

const safeJson = (raw: string | null): Dict => {
	try {
		const value = raw === null ? null : JSON.parse(raw);
		return isDict(value) ? value : {};
	} catch {
		return {};
	}
};
