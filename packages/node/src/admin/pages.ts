import { type ActionCtx, Column, Cond, definePage, Effects, HttpError, type S, Tab } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, isLive, type Page, TRANSLATABLE } from "../content/page";
import { PageConflictError } from "../content/pages";
import { allowsChild, PageRuleError, subtreeIds } from "../content/pageRules";
import { previewLink } from "../frontend/previewLink";
import type { Site } from "../site";
import { type Dict, isDict } from "../support/values";
import { allows } from "./abilities";
import { formParentId, formSubtype, resolveLeafType, subtypeOptions } from "./pageLeafType";
import { adminPrefix, blockId, blockRecord, blockRows, blockTabs, NAV, rowProblems, rowsFromForm, siteOf, trimmedForm } from "./support";

const STATUS = [
	{ value: "draft", label: "Draft" },
	{ value: "published", label: "Published" },
];

const typeLabel = (cms: Cms, key: string) => (cms.pageTypes.has(key) ? cms.pageTypes.get(key).label : key);

/** Pages list (PagesPage): a tab per base type, the create modal. */
export function pagesIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "pages",
		title: "Pages",
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
				.onSubmit(async (submitted) => {
					const ctx = trimmedForm(submitted);
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

type Option = { value: string; label: string };

/** Parent select options: each page's name in `locale`, or its id. */
function parentOptions(rows: { id: number; name: string | null }[], locale: string): Option[] {
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

const userKey = (ctx: ActionCtx): string | number | null => {
	const user = ctx.user as { id?: unknown } | null;
	return typeof user?.id === "number" || typeof user?.id === "string" ? user.id : null;
};

/** Page editor (PageEditPage): General, SEO and a tab per block, with a sidebar of status and parent. */
export function pageEdit(cms: Cms) {
	const prefix = adminPrefix(cms);
	const load = async (request: Request, id: string) => {
		const page = await cms.site(request).pages.find(Number(id));
		if (page === null) {
			throw new HttpError(404, "Page not found.");
		}
		return page;
	};
	return definePage({
		path: "pages/$page/edit",
		title: "Edit page",
		breadcrumbs: [{ label: "Content" }, { label: "Pages", url: `${prefix}/pages` }, { label: "Edit" }],
		view: async (s, { params, request }) => {
			const site = cms.site(request);
			const page = await load(request, params.page ?? "");
			const back = `${prefix}/pages/${page.id}/edit`;
			const rows = blockRows(page.content);
			const slugs = await site.pages.slugs.allFor(page.id);
			const sharedBlocks = await site.sharedBlocks.all();
			const shared = new Map(sharedBlocks.map((block) => [block.id, block.name]));
			const problems = await rowProblems(site, rows);
			// Categories (a page under one becomes an article) and parents whose type takes this page's type; never the page itself or its descendants.
			const own = await subtreeIds(cms.config.db, page.id);
			const parents = (await site.pages.query().selectAll("tbtop_pages").orderBy("id").execute()).filter(
				(row) => !own.has(Number(row.id)) && (row.type === "category" || allowsChild(cms.pageTypes, row.type, page.type)),
			);
			const defaultLocale = await site.languages.defaultLocale();
			// A structural save keeps the stored status: a status change waits for the validated Save (structuralForm).
			const save = async (ctx: ActionCtx, content: Dict[]) => {
				try {
					await savePage(cms, ctx, page, content, { structural: true });
				} catch (error) {
					return saveRefusal(error);
				}
			};
			// Shared blocks are design, not page content: sharing and placing one need that ability (PageEditBlockTabs::mayEditShared).
			const mayShare = await allows(cms, "tbtop-cms.design");
			const previews = await previewActions(s, site, page, slugs);
			return s.stack([
				s.displayText(firstText(page, ["name", "title"], defaultLocale, defaultLocale) || `#${page.id}`).variant("heading"),
				s.displayText(`${isLive(page) ? "Live" : "Not live"} · /${slugs[defaultLocale] ?? ""}`).variant("muted"),
				...(previews.length > 0 ? [s.row(previews)] : []),
				s
					.form("pageEditor", [
						s.number("lock_version").meta("hidden", true),
						s.tabs([
							{ label: "General", body: s.stack(generalFields(s, page)) },
							{ label: "SEO", body: s.stack(seoFields(s, cms, page)) },
							...blockTabs(s, cms, rows, {
									placement: "pages",
									save,
									back,
									sharedNames: shared,
									problems,
									skipsValidation: page.status !== "published",
									...(mayShare ? { sharing: { blocks: sharedBlocks, owner: firstText(page, ["name"], defaultLocale, defaultLocale) || `#${page.id}` } } : {}),
								}),
							{
								label: "Publishing",
								body: s.stack([
									s.select("status").label("Status").options(STATUS).required().in(["draft", "published"]),
									s.datetime("published_at").label("Publish at"),
									s.datetime("unpublish_at").label("Unpublish at"),
									s.boolean("is_indexable").label("Search engines may index this page"),
									...typeFields(s, cms, page),
									s.select("parent_id").label("Parent").options(parentOptions(parents, defaultLocale)),
									s.number("sort_order").label("Sort order").rules("nullable|integer|min:0"),
								]),
							},
						]),
						s.actionsRow([
							s.action("save").label("Save").color("primary").keybinding("mod+s").submit(),
							s.action("back").label("Back to pages").url(`${prefix}/pages`),
						]),
					])
					.record({
						...Object.fromEntries(TRANSLATABLE.map((field) => [field, page[field]])),
						slug: Object.fromEntries(Object.entries(slugs).map(([locale, path]) => [locale, path.split("/").at(-1) ?? ""])),
						status: page.status,
						published_at: page.publishedAt?.toISOString() ?? null,
						unpublish_at: page.unpublishAt?.toISOString() ?? null,
						is_indexable: page.isIndexable,
						type: page.type,
						subtype: page.subtype ?? "",
						parent_id: page.parentId === null ? null : String(page.parentId),
						sort_order: page.sortOrder,
						lock_version: page.lockVersion,
						image: page.image ?? null,
						...seoRecord(page),
						...blockRecord(cms.config.blocks, rows),
					})
					.onSubmit(async (submitted) => {
						const ctx = trimmedForm(submitted);
						try {
							await savePage(cms, ctx, page, rowsFromForm(cms.config.blocks, rows, ctx.form as Dict));
						} catch (error) {
							return saveRefusal(error);
						}
						return Effects.make().notify("Saved").redirect(back);
					}),
			]);
		},
	});
}

/**
 * PageEditStatusBar::previewLinkAction: for a page that is not live, a
 * signed link per site locale with a saved slug, opened in a new tab. A
 * live page, an error page and a config without `preview.secret` get none.
 */
async function previewActions(s: S, site: Site, page: Page, slugs: Record<string, string>): Promise<unknown[]> {
	if (isLive(page) || page.errorClass !== null || site.config.preview === undefined) {
		return [];
	}
	const actions: unknown[] = [];
	for (const { locale } of await site.languages.frontendActive()) {
		const path = slugs[locale];
		const url = path === undefined ? null : await previewLink(site, path, locale);
		if (url !== null) {
			actions.push(s.action(`pagePreviewLink_${locale}`).label(`Preview (${locale})`).icon("eye").url(url).openUrlInNewTab());
		}
	}
	return actions;
}

/** A stale form or a broken page rule as an error notice (PageConflictException → pages.conflict.message); anything else rethrows. */
function saveRefusal(error: unknown): Effects {
	if (error instanceof PageConflictError) {
		return Effects.make().notify("Someone else saved this page meanwhile. Reload to see their changes.", "error");
	}
	if (error instanceof PageRuleError) {
		return Effects.make().notify(error.message, "error");
	}
	throw error;
}

const pageLabel = (row: { name: string | null }): Page => ({ name: safeJson(row.name) }) as unknown as Page;

const safeJson = (raw: string | null): Dict => {
	try {
		const value = raw === null ? null : JSON.parse(raw);
		return isDict(value) ? value : {};
	} catch {
		return {};
	}
};

/**
 * PageEditSidebar's type select and the stored base's subtype select; the
 * subtype stays until cleared, since a type change that leaves one behind is
 * refused. A system page keeps its type.
 */
function typeFields(s: S, cms: Cms, page: Page): unknown[] {
	if (page.isSystem) {
		return [];
	}
	const types = cms.pageTypes.bases().map((type) => ({ value: type.key, label: type.label }));
	const subtypes = subtypeOptions(cms.pageTypes, page.type);
	return [
		s.select("type").label("Type").options(types).required(),
		...(subtypes.length === 0
			? []
			: [s.select("subtype").label("Subtype").options(subtypes).hiddenIf(Cond.all(Cond.neq("type", page.type), Cond.empty("subtype")))]),
	];
}

function generalFields(s: S, page: Page): unknown[] {
	return [
		s.text("name").label("Name").required().translatable().helperText("Shown in menus, breadcrumbs and the admin."),
		s.text("title").label("Title").translatable().helperText("The page's own title, when it differs from the name."),
		...(page.isSystem ? [] : [s.slug("slug").label("Slug").translatable().fromField("name")]),
		s.text("heading").label("Heading").translatable(),
		s.textarea("summary").label("Summary").translatable(),
		s.richtext("body").label("Body").translatable(),
		s.upload("image").label("Image").accept("image/*"),
	];
}

function seoFields(s: S, cms: Cms, page: Page): unknown[] {
	const schemaKeys = cms.pageTypes.has(page.type) ? cms.pageTypes.schemaTypesFor(page).filter((key) => cms.schemaTypes.has(key)) : [];
	return [
		s.text("seo_title").label("SEO title").translatable().rules("nullable|max:255"),
		s.textarea("seo_description").label("Meta description").translatable(),
		s.text("og_title").label("Social title").translatable(),
		s.textarea("og_description").label("Social description").translatable(),
		s.upload("og_image").label("Social image").accept("image/*"),
		s.text("seo_canonical").label("Canonical URL").translatable(),
		...(schemaKeys.length > 1
			? [s.select("schema_type").label("Schema type").options(schemaKeys.map((key) => ({ value: key, label: cms.schemaTypes.get(key).label ?? key })))]
			: []),
	];
}

function seoRecord(page: Page): Dict {
	const seo = page.seo;
	const schema = isDict(seo.schema) ? seo.schema : {};
	return {
		seo_title: seo.title ?? null,
		seo_description: seo.description ?? null,
		og_title: seo.og_title ?? null,
		og_description: seo.og_description ?? null,
		og_image: typeof seo.og_image === "string" && seo.og_image !== "" ? seo.og_image : null,
		seo_canonical: seo.canonical ?? null,
		schema_type: schema.type ?? null,
	};
}

/** PageFormValues::nullableDateTime: blank or unparsable is null. */
const date = (value: unknown): Date | null => {
	if (typeof value !== "string" || value === "") {
		return null;
	}
	const parsed = new Date(value);
	return Number.isNaN(parsed.getTime()) ? null : parsed;
};

/**
 * The type, subtype and parent the editor saves (PageFormWriter::resolveTypeAndParent):
 * PageLeafType settles a page or article by its parent. A system page keeps all three.
 */
async function editedTree(cms: Cms, ctx: ActionCtx, page: Page): Promise<Partial<Pick<Page, "type" | "subtype" | "parentId">>> {
	const form = ctx.form as Dict;
	if (page.isSystem || !("type" in form || "parent_id" in form)) {
		return {};
	}
	const picked = typeof form.type === "string" && form.type !== "" ? form.type : page.type;
	const parent = "parent_id" in form ? formParentId(form.parent_id) : page.parentId;
	const tree = await resolveLeafType(siteOf(cms, ctx), cms.pageTypes, page, picked, parent === page.id ? page.parentId : parent);
	return { ...tree, ...("subtype" in form ? { subtype: formSubtype(form.subtype) } : {}) };
}

/** PageFormGuards::scheduling: with both dates set, the page must go live before it goes dark. */
function assertSchedule(form: Dict): void {
	const publishedAt = date(form.published_at);
	const unpublishAt = date(form.unpublish_at);
	if (publishedAt !== null && unpublishAt !== null && unpublishAt.getTime() <= publishedAt.getTime()) {
		throw new PageRuleError("The unpublish time must be after the publish time.");
	}
}

/** The editor's translatable SEO fields by their key in the seo bag; og_image is a plain path. */
const SEO_MAPS: [string, string][] = [
	["seo_title", "title"],
	["seo_description", "description"],
	["og_title", "og_title"],
	["og_description", "og_description"],
	["seo_canonical", "canonical"],
];

/** PageFormValues::stringMap: a locale map of strings; anything but a map is empty, a null entry "". */
const stringMap = (value: unknown): Record<string, string> =>
	isDict(value) ? Object.fromEntries(Object.entries(value).map(([locale, entry]) => [locale, entry === null || entry === undefined ? "" : String(entry)])) : {};

/**
 * PageFormWriter::save for the editor: columns, seo blob, block rows, slugs; optimistic lock on lock_version.
 * A `structural` save (add or remove a block) writes the stored status, never the form's.
 */
async function savePage(cms: Cms, ctx: ActionCtx, page: Page, content: Dict[], options: { structural?: boolean } = {}): Promise<void> {
	const site = siteOf(cms, ctx);
	const form = ctx.form as Dict;
	const seo: Dict = { ...page.seo };
	for (const [field, key] of SEO_MAPS) {
		if (field in form) {
			seo[key] = stringMap(form[field]);
		}
	}
	if ("og_image" in form) {
		seo.og_image = typeof form.og_image === "string" ? form.og_image : "";
	}
	if (typeof form.schema_type === "string" && form.schema_type !== "") {
		seo.schema = { ...(isDict(seo.schema) ? seo.schema : {}), type: form.schema_type };
	}
	assertSchedule(form);
	const tree = await editedTree(cms, ctx, page);
	await site.pages.update(
		page.id,
		{
			...Object.fromEntries(TRANSLATABLE.filter((field) => field in form).map((field) => [field, isDict(form[field]) ? form[field] : {}])),
			...("status" in form && options.structural !== true ? { status: form.status === "published" ? "published" : "draft" } : {}),
			...("published_at" in form ? { publishedAt: date(form.published_at) } : {}),
			...("unpublish_at" in form ? { unpublishAt: date(form.unpublish_at) } : {}),
			...("is_indexable" in form ? { isIndexable: Boolean(form.is_indexable) } : {}),
			...tree,
			...("sort_order" in form && form.sort_order !== null ? { sortOrder: Number(form.sort_order) || 0 } : {}),
			...("image" in form ? { image: typeof form.image === "string" && form.image !== "" ? form.image : null } : {}),
			seo,
			content,
		},
		{
			slugs: isDict(form.slug) ? (Object.fromEntries(Object.entries(form.slug).map(([k, v]) => [k, String(v ?? "")])) as Record<string, string>) : {},
			lockVersion: typeof form.lock_version === "number" ? form.lock_version : Number(form.lock_version ?? page.lockVersion),
			by: userKey(ctx),
		},
	);
}
