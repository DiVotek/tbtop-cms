import { type ActionCtx, Column, Cond, definePage, Effects, type S, Tab } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, type Page } from "../content/page";
import { PageRuleError } from "../content/pageRules";
import { type Dict, isDict } from "../support/values";
import { formParentId, formSubtype, resolveLeafType, subtypeOptions } from "./pageLeafType";
import { adminPrefix, blockId, listHeader, NAV, siteOf } from "./support";

export const STATUS = [
	{ value: "draft", label: "Draft" },
	{ value: "published", label: "Published" },
];

const typeLabel = (cms: Cms, key: string) => (cms.pageTypes.has(key) ? cms.pageTypes.get(key).label : key);

/** Pages list (PagesPage): a tab per base type, the create modal. */
export function pagesIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "pages",
		...listHeader("content", "Pages"),
		nav: { group: NAV.content, label: "Pages", icon: "files", order: 10 },
		view: async (s, { request }) => {
			const site = cms.site(request);
			const locale = await site.languages.defaultLocale();
			const all = await site.pages.query().select(["id", "name"]).execute();
			const names = new Map(all.map((row) => [Number(row.id), row.name]));
			const paths = await site.pages.slugs.pathsFor(
				all.map((row) => Number(row.id)),
				locale,
			);
			const nameOf = (raw: unknown) => {
				try {
					const map = typeof raw === "string" ? JSON.parse(raw) : raw;
					return isDict(map) ? String(map[locale] ?? Object.values(map)[0] ?? "") : "";
				} catch {
					return "";
				}
			};
			const categories = await site.pages.query().select(["id", "name"]).where("type", "=", "category").orderBy("sort_order").orderBy("id").execute();
			return s.stack([
				s.row([createAction(s, cms, parentOptions(categories, locale))]),
				s
					.table("pages")
					.columns([
						Column.make("name").label("Title").translatable().emphasized().searchable(),
						Column.make("type")
							.label("Type")
							.formatUsing((value) => typeLabel(cms, String(value))),
						// Only once a host registers a subtype: before that every row would be blank (PagesPage::columns).
						...(cms.pageTypes.all().some((type) => type.base !== type.key)
							? [
									Column.make("subtype")
										.label("Subtype")
										.muted()
										.formatUsing((value) => (typeof value === "string" && value !== "" ? typeLabel(cms, value) : "")),
								]
							: []),
						Column.make("parent_id")
							.label("Parent")
							.muted()
							.formatUsing((value) => (value === null ? "" : nameOf(names.get(Number(value))))),
						Column.make("status")
							.label("Status")
							.badge({ published: { label: "Published", color: "success" }, draft: { label: "Draft" } }),
						Column.make("path")
							.label("Path")
							.muted()
							.formatUsing((_, row) => `/${paths.get(Number(row.id)) ?? ""}`),
						Column.make("updated_at").label("Updated").datetime().sortable(),
					])
					.tabs([
						Tab.make("all").label("All"),
						...cms.pageTypes.bases().map((type) =>
							Tab.make(type.key)
								.label(type.label)
								.query((qb) => (qb as ReturnType<typeof site.pages.query>).where("type", "=", type.key)),
						),
					])
					.defaultSort("id", "asc")
					.paginate(25, [25, 50, 100])
					.recordUrl((row) => `${prefix}/pages/${String(row.id)}/edit`)
					.rowActions([
						s
							.action("edit")
							.label("Edit")
							.icon("pencil")
							.handle((ctx) => `${prefix}/pages/${String(ctx.key())}/edit`, ["row"]),
						s
							.action("delete")
							.label("Delete")
							.color("danger")
							.confirm("Delete this page?", "The page goes to the trash and its URL stops working.")
							.handle(async (ctx) => {
								const page = await siteOf(cms, ctx).pages.find(Number(ctx.key()));
								if (page === null) {
									return Effects.make().notify("The page is already gone.", "error");
								}
								try {
									await siteOf(cms, ctx).pages.delete(page.id);
								} catch (error) {
									if (error instanceof PageRuleError) {
										return Effects.make().notify(error.message, "error");
									}
									throw error;
								}
								return Effects.make().notify("Page deleted").refreshTable("pages");
							}, ["row"]),
					])
					.query(() => site.pages.query()),
			]);
		},
	});
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
					s.actionsRow([s.action("pageCreateSave").label("Create").color("primary").submit()]),
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
