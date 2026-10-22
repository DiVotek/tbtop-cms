import { type ActionCtx, Column, definePage, Effects, HttpError, type S, Tab } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, isLive, type Page, TRANSLATABLE } from "../content/page";
import { PageConflictError } from "../content/pages";
import { allowsChild, PageRuleError, subtreeIds } from "../content/pageRules";
import { type Dict, isDict } from "../support/values";
import { adminPrefix, blockRecord, blockRows, blockTabs, NAV, rowsFromForm, siteOf } from "./support";

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
			return s.stack([
				s.row([createAction(s, cms)]),
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

function createAction(s: S, cms: Cms) {
	const prefix = adminPrefix(cms);
	const types = cms.pageTypes.bases().map((type) => ({ value: type.key, label: type.label }));
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
					s.actionsRow([s.action("pageCreateSave").label("Create").color("primary").submit()]),
				])
				.onSubmit(async (ctx) => {
					const site = siteOf(cms, ctx);
					const form = ctx.form as Dict;
					const type = String(form.type ?? "page");
					if (!cms.pageTypes.has(type)) {
						return Effects.make().notify("Unknown page type.", "error");
					}
					try {
						const page = await site.pages.create({ type, name: isDict(form.name) ? form.name : {}, content: [] }, { by: userKey(ctx) });
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
			const shared = new Map((await site.sharedBlocks.all()).map((block) => [block.id, block.name]));
			// Parents whose type takes this page's type; never the page itself or its descendants.
			const own = await subtreeIds(cms.config.db, page.id);
			const parents = (await site.pages.query().selectAll("tbtop_pages").orderBy("id").execute()).filter(
				(row) => !own.has(Number(row.id)) && allowsChild(cms.pageTypes, row.type, page.type),
			);
			const defaultLocale = await site.languages.defaultLocale();
			const save = async (ctx: ActionCtx, content: Dict[]) => {
				await savePage(cms, ctx, page, content);
			};
			return s.stack([
				s.displayText(firstText(page, ["name", "title"], defaultLocale, defaultLocale) || `#${page.id}`).variant("heading"),
				s.displayText(`${isLive(page) ? "Live" : "Not live"} · /${slugs[defaultLocale] ?? ""}`).variant("muted"),
				s
					.form("pageEditor", [
						s.number("lock_version").meta("hidden", true),
						s.tabs([
							{ label: "General", body: s.stack(generalFields(s, page)) },
							{ label: "SEO", body: s.stack(seoFields(s, cms, page)) },
							...blockTabs(s, cms, rows, { placement: "pages", save, back, sharedNames: shared }),
							{
								label: "Publishing",
								body: s.stack([
									s.select("status").label("Status").options(STATUS).required().in(["draft", "published"]),
									s.datetime("published_at").label("Publish at"),
									s.datetime("unpublish_at").label("Unpublish at"),
									s.boolean("is_indexable").label("Search engines may index this page"),
									s
										.select("parent_id")
										.label("Parent")
										.options(parents.map((row) => ({ value: String(row.id), label: firstText(pageLabel(row), ["name"], defaultLocale, defaultLocale) || `#${row.id}` }))),
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
						parent_id: page.parentId === null ? null : String(page.parentId),
						sort_order: page.sortOrder,
						lock_version: page.lockVersion,
						image: page.image ?? null,
						...seoRecord(page),
						...blockRecord(cms.config.blocks, rows),
					})
					.onSubmit(async (ctx) => {
						try {
							await savePage(cms, ctx, page, rowsFromForm(cms.config.blocks, rows, ctx.form as Dict));
						} catch (error) {
							if (error instanceof PageConflictError) {
								return Effects.make().notify("Someone else saved this page meanwhile. Reload to see their changes.", "error");
							}
							if (error instanceof PageRuleError) {
								return Effects.make().notify(error.message, "error");
							}
							throw error;
						}
						return Effects.make().notify("Saved").redirect(back);
					}),
			]);
		},
	});
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
		og_image: seo.og_image ?? null,
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

/** PageFormGuards::scheduling: with both dates set, the page must go live before it goes dark. */
function assertSchedule(form: Dict): void {
	const publishedAt = date(form.published_at);
	const unpublishAt = date(form.unpublish_at);
	if (publishedAt !== null && unpublishAt !== null && unpublishAt.getTime() <= publishedAt.getTime()) {
		throw new PageRuleError("The unpublish time must be after the publish time.");
	}
}

const SEO_KEYS: [string, string][] = [
	["seo_title", "title"],
	["seo_description", "description"],
	["og_title", "og_title"],
	["og_description", "og_description"],
	["og_image", "og_image"],
	["seo_canonical", "canonical"],
];

/** PageFormWriter::save for the editor: columns, seo blob, block rows, slugs; optimistic lock on lock_version. */
async function savePage(cms: Cms, ctx: ActionCtx, page: Page, content: Dict[]): Promise<void> {
	const site = siteOf(cms, ctx);
	const form = ctx.form as Dict;
	const seo: Dict = { ...page.seo };
	for (const [field, key] of SEO_KEYS) {
		if (field in form) {
			seo[key] = form[field] ?? "";
		}
	}
	if (typeof form.schema_type === "string" && form.schema_type !== "") {
		seo.schema = { ...(isDict(seo.schema) ? seo.schema : {}), type: form.schema_type };
	}
	assertSchedule(form);
	const parent = form.parent_id === null || form.parent_id === undefined || form.parent_id === "" ? null : Number(form.parent_id);
	await site.pages.update(
		page.id,
		{
			...Object.fromEntries(TRANSLATABLE.filter((field) => field in form).map((field) => [field, isDict(form[field]) ? form[field] : {}])),
			...("status" in form ? { status: form.status === "published" ? "published" : "draft" } : {}),
			...("published_at" in form ? { publishedAt: date(form.published_at) } : {}),
			...("unpublish_at" in form ? { unpublishAt: date(form.unpublish_at) } : {}),
			...("is_indexable" in form ? { isIndexable: Boolean(form.is_indexable) } : {}),
			...("parent_id" in form && !page.isSystem ? { parentId: parent === page.id ? page.parentId : parent } : {}),
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
