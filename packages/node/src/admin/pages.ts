import { type ActionCtx, Column, Cond, definePage, Effects, type S, Tab } from "@tbtop/admin";
import { type SelectQueryBuilder, sql } from "kysely";
import type { Cms } from "../cms";
import type { CmsDatabase } from "../db/schema";
import { firstText, type Page } from "../content/page";
import { PageRuleError } from "../content/pageRules";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";
import { dialectOfDb } from "../support/db";
import { formParentId, formSubtype, resolveLeafType, subtypeOptions } from "./pageLeafType";
import { adminPrefix, blockId, listHeader, NAV, siteOf } from "./support";

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

/** A translatable column with no non-blank value in any locale. */
const blank = (raw: unknown): boolean => {
	const value = decodeJson(raw);
	return !(isDict(value) ? Object.values(value) : [value]).some((text) => typeof text === "string" && text.trim() !== "");
};

/** PageListState::seoState and seoTooltip: what the badge says, and why. */
function seoState(row: Dict): { state: keyof typeof SEO_STATES; reasons: string[] } {
	const noTitle = blank(row.name) && blank(row.title);
	const noSummary = blank(row.summary);
	const hidden = !truthy(row.is_indexable);
	const reasons = [...(noTitle ? ["No name and no title"] : []), ...(noSummary ? ["No summary"] : []), ...(hidden ? ["Excluded from indexing"] : [])];
	return { state: noTitle ? "fail" : noSummary || hidden ? "warn" : "pass", reasons };
}

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
			const present = (await site.pages.query().select("type").distinct().execute()).map((row) => row.type);
			const keys = cms.pageTypes.bases().map((type) => type.key);
			const tabTypes = [...keys.filter((key) => present.includes(key)), ...present.filter((key) => !keys.includes(key)).sort()];
			const live = (qb: unknown) => (qb as PagesTable).where("tbtop_pages.deleted_at", "is", null);
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
							.formatUsing((_, row) => SEO_STATES[seoState(row as Dict).state].label)
							.tooltip((row) => seoState(row as Dict).reasons.join("\n"))
							.badge(badges(SEO_STATES))
							.toggleable(),
						Column.make("updated_at").label("Updated").date("M j, Y").muted().sortable(),
						// Projected for the row actions' conditions and the view link, not shown.
						Column.make("deleted_at").label("Deleted").date("M j, Y").toggleable(true, true),
						Column.make("path").label("Path").muted().toggleable(true, true),
					])
					.tabs([
						tab("all", "All").query(live),
						...tabTypes.map((type) => tab(type, typeLabel(cms, type)).query((qb) => live(qb).where("tbtop_pages.type", "=", type))),
						Tab.make("deleted")
							.label("Deleted")
							.count()
							.description("Pages moved to the trash — restore them or delete permanently.")
							.query((qb) => (qb as PagesTable).where("tbtop_pages.deleted_at", "is not", null)),
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
									sql<string>`case when tbtop_pages.status <> 'published' then 'draft' when tbtop_pages.published_at is not null and tbtop_pages.published_at > ${now} then 'scheduled' when tbtop_pages.unpublish_at is not null and tbtop_pages.unpublish_at <= ${now} then 'expired' else 'published' end`.as("display_state"),
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
			.hiddenIf(Cond.notEmpty("deleted_at")),
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
	const bases = cms.pageTypes.bases();
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
					s.text("title").label("Title").translatable().rules("nullable|max:255"),
					s.select("type").label("Type").options(types).required().default("page"),
					...subtypes,
					parent,
					s.flex([s.spacer(), s.action("pageCreateCancel").label("Cancel").handle(() => Effects.make().closeModal()), s.action("pageCreateSave").label("Create").color("primary").submit()], { justify: "end" }),
				])
				.onSubmit(async (ctx) => {
					const form = ctx.form as Dict;
					const type = String(form.type ?? "page");
					if (!cms.pageTypes.has(type)) {
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

/** PageFormWriter::save on create: the title falls back to the name, the content starts from the type's template. */
async function createPage(cms: Cms, ctx: ActionCtx, tree: { type: string; parentId: number | null; subtype: string | null }): Promise<Page> {
	const form = ctx.form as Dict;
	const name = isDict(form.name) ? form.name : {};
	const title = withTitleFallback(name, isDict(form.title) ? form.title : {});
	const content = templateContent(cms, draftPage({ ...tree, name, title }));
	return siteOf(cms, ctx).pages.create({ ...tree, name, title, content }, { by: userKey(ctx) });
}

/** PageFormAttributes::withTitleFallback: per locale, a blank title takes the name. Create only. */
function withTitleFallback(name: Dict, title: Dict): Dict {
	const out: Dict = { ...title };
	for (const [locale, value] of Object.entries(name)) {
		const current = out[locale];
		if (typeof value === "string" && value !== "" && (typeof current !== "string" || current === "")) {
			out[locale] = value;
		}
	}
	return out;
}

/**
 * TypeTemplateContent::contentFor: the base type's template() with a fresh id
 * per block, and active/sort/data defaults the template may leave out. The
 * frontend renders template() only for a null content, so an admin-made page
 * starts with the rows instead of `[]`.
 */
function templateContent(cms: Cms, page: Page): Dict[] {
	if (!cms.pageTypes.has(page.type)) {
		return [];
	}
	return (cms.pageTypes.get(page.type).template(page) ?? [])
		.filter(isDict)
		.map((block, index) => ({ active: true, sort: index, data: {}, ...block, id: blockId() }));
}

/** The page about to be created, for template(): PHP passes the unsaved model. */
const draftPage = (input: Pick<Page, "type" | "parentId" | "subtype" | "name" | "title">): Page => ({
	id: 0,
	sourceId: null,
	status: "draft",
	publishedAt: null,
	unpublishAt: null,
	content: null,
	fields: null,
	starterBlocks: null,
	heading: {},
	summary: {},
	body: {},
	image: null,
	seo: {},
	isIndexable: true,
	isSystem: false,
	errorClass: null,
	sortOrder: 0,
	lockVersion: 0,
	createdBy: null,
	updatedBy: null,
	createdAt: null,
	updatedAt: null,
	...input,
});

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
