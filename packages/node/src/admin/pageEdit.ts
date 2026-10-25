import { type ActionCtx, Cond, definePage, Effects, HttpError, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { firstText, isLive, type Page, TRANSLATABLE } from "../content/page";
import { PageConflictError } from "../content/pages";
import { allowsChild, PageRuleError, subtreeIds } from "../content/pageRules";
import { ancestorCrumbs } from "../frontend/breadcrumbs";
import { previewLink } from "../frontend/previewLink";
import { renderedDescription, renderedTitle } from "../seo/resolver";
import type { Site } from "../site";
import { type Dict, isDict } from "../support/values";
import { allows } from "./abilities";
import { formParentId, formSubtype, resolveLeafType, subtypeOptions } from "./pageLeafType";
import { parentOptions, STATUS, userKey } from "./pages";
import { foldTypeFields, typeFieldRecord, typeFieldSections } from "./pageTypeFields";
import { revisionsAction } from "./revisions";
import { adminPrefix, blockRecord, blockRows, blockTabs, rowProblems, rowsFromForm, siteOf } from "./support";

/** The header the view computed, read by title, subtitle and breadcrumbs (which cannot await the page). */
const headers = new WeakMap<Request, { title: string; subtitle: string }>();

/**
 * Page editor (PageEditPage): a status bar (badge, timestamps, revisions,
 * view-on-site, top save), a two-column grid (General, SEO and a tab per
 * block on the left; Publication, Structure, Ordering and Image cards on
 * the right) and a footer (delete … cancel, save). The page's name is the
 * title, its path the subtitle.
 */
export function pageEdit(cms: Cms) {
	const prefix = adminPrefix(cms);
	const header = (request: Request) => headers.get(request) ?? { title: "Edit page", subtitle: "" };
	return definePage({
		path: "pages/$page/edit",
		title: ({ request }) => header(request).title,
		subtitle: ({ request }) => header(request).subtitle,
		breadcrumbs: ({ request }) => [{ label: "Content" }, { label: "Pages", url: `${prefix}/pages` }, { label: header(request).title }],
		view: async (s, { params, request }) => {
			const site = cms.site(request);
			const page = await site.pages.find(Number(params.page ?? ""));
			if (page === null) {
				throw new HttpError(404, "Page not found.");
			}
			const defaultLocale = await site.languages.defaultLocale();
			const slugs = await site.pages.slugs.allFor(page.id);
			const title = firstText(page, ["name"], defaultLocale, defaultLocale) || `#${page.id}`;
			const path = slugs[defaultLocale];
			headers.set(request, { title, subtitle: path === undefined ? "" : `/${path}` });
			const back = `${prefix}/pages/${page.id}/edit`;
			const rows = blockRows(page.content);
			const saveTop = s.action("saveTop").label("Save").color("primary").submit("pageEditor");
			const save = s.action("save").label("Save").color("primary").keybinding("mod+s").submit("pageEditor");
			const remove = (await canDelete(site, page)) ? deleteAction(s, cms, page) : null;
			return s.stack([
				s
					.form("pageEditor", [
						// A real field, hidden: the validated payload keeps only keys a field declares.
						s.number("lock_version").meta("hidden", true),
						await statusBar(s, cms, site, page, slugs, back, saveTop),
						s.grid({ cols: { sm: 1, lg: 3 }, gap: 6 }, [
							s.stack([s.tabs(await tabs(s, cms, site, page, rows, back, defaultLocale))], { colSpan: { sm: 1, lg: 2 } }),
							await sidebar(s, cms, site, page, defaultLocale),
						]),
						s.flex([...(remove === null ? [] : [remove]), s.spacer(), s.action("cancel").label("Cancel").url(`${prefix}/pages`), save], { justify: "end" }),
					])
					.record({
						...Object.fromEntries(TRANSLATABLE.map((field) => [field, page[field]])),
						slug: Object.fromEntries(Object.entries(slugs).map(([locale, full]) => [locale, full.split("/").at(-1) ?? ""])),
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
						...seoRecord(cms, page),
						...typeFieldRecord(cms, page),
						...blockRecord(cms.config.blocks, rows),
					})
					.onSubmit(async (ctx) => {
						try {
							await savePage(cms, ctx, page, rowsFromForm(cms.config.blocks, rows, ctx.form as Dict));
						} catch (error) {
							return saveRefusal(error);
						}
						return Effects.make().notify("Page saved").redirect(back);
					}),
			]);
		},
	});
}

async function tabs(s: S, cms: Cms, site: Site, page: Page, rows: Dict[], back: string, defaultLocale: string) {
	const sharedBlocks = await site.sharedBlocks.all();
	const problems = await rowProblems(site, rows);
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
	return [
		{ label: "General", body: s.stack([...generalFields(s, page), ...typeFieldSections(s, cms, page)]) },
		{ label: "SEO", body: s.stack(await seoFields(s, cms, site, page, defaultLocale)) },
		...blockTabs(s, cms, rows, {
			// A block may name the page's type or its subtype in placements (PageEditBlockTabs::placements).
			placement: page.subtype !== null && page.subtype !== "" ? [page.type, page.subtype] : [page.type],
			save,
			back,
			sharedBlocks,
			problems,
			skipsValidation: page.status !== "published",
			...(mayShare ? { sharing: { owner: firstText(page, ["name"], defaultLocale, defaultLocale) || `#${page.id}` } } : {}),
		}),
	];
}

/** PageDisplayState: what the badge says, from the status and the schedule. */
function displayState(page: Page, now = new Date()): "draft" | "scheduled" | "published" | "expired" {
	if (page.status !== "published") {
		return "draft";
	}
	if (page.publishedAt !== null && page.publishedAt > now) {
		return "scheduled";
	}
	return page.unpublishAt !== null && page.unpublishAt <= now ? "expired" : "published";
}

const STATE_BADGES = {
	draft: { label: "Draft", color: "warning" },
	scheduled: { label: "Scheduled", color: "gray" },
	published: { label: "Published", color: "success" },
	expired: { label: "Expired", color: "gray" },
} as const;

/**
 * PageEditStatusBar: the state badge (with when a scheduled or expired page
 * changes), unsaved marker, timestamps, revisions, view on site, the draft
 * preview links and a top save, as one card row.
 */
async function statusBar(s: S, cms: Cms, site: Site, page: Page, slugs: Record<string, string>, back: string, saveTop: unknown) {
	const state = displayState(page);
	const badge = STATE_BADGES[state];
	const note =
		state === "scheduled" && page.publishedAt !== null
			? [s.displayText("Scheduled for").variant("muted"), s.displayValue(page.publishedAt.toISOString()).datetime("d.m.Y H:i")]
			: state === "expired" && page.unpublishAt !== null
				? [s.displayText("Expired").variant("muted"), s.displayValue(page.unpublishAt.toISOString()).datetime("d.m.Y H:i")]
				: [];
	const revisions = await revisionsAction(s, cms, site, page, back);
	return s.flex(
		[
			s.displayValue(badge.label).badge({ [badge.label]: { color: badge.color } }),
			...note,
			s.unsavedIndicator(),
			s.spacer(),
			...timestamps(s, page),
			...(revisions === null ? [] : [revisions]),
			...viewOnSite(s, page, slugs, await site.languages.defaultLocale()),
			...(await previewActions(s, site, page, slugs)),
			saveTop,
		],
		{ align: "center", gap: 3, wrap: true, variant: "card", class: "text-xs" },
	);
}

/** Updated with its date and time; created, date only, when it differs. A bare icon names each. */
function timestamps(s: S, page: Page): unknown[] {
	const icon = (name: string) => s.displayValue(name).icon({ [name]: { icon: name } });
	const out: unknown[] = [];
	if (page.updatedAt !== null) {
		out.push(s.flex([icon("pencil"), s.displayValue(page.updatedAt.toISOString()).datetime("d.m.Y H:i")], { align: "center", gap: 1 }));
	}
	if (page.createdAt !== null && page.createdAt.getTime() !== page.updatedAt?.getTime()) {
		out.push(s.flex([icon("calendar-plus"), s.displayValue(page.createdAt.toISOString()).date("d.m.Y")], { align: "center", gap: 1 }));
	}
	return out;
}

/** PageEditPage::viewOnSiteAction: the page's address in the default locale; an error page has none. */
function viewOnSite(s: S, page: Page, slugs: Record<string, string>, locale: string): unknown[] {
	const path = slugs[locale];
	if (page.errorClass !== null || path === undefined) {
		return [];
	}
	return [s.action("pageEditView").label("View on site").icon("external-link").url(`/${path}`).openUrlInNewTab()];
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

/**
 * PageEditSidebar: Publication (status, indexable, the schedule once
 * published), Structure (type, subtype, parent), Ordering and Image cards.
 */
async function sidebar(s: S, cms: Cms, site: Site, page: Page, defaultLocale: string) {
	// Categories (a page under one becomes an article) and parents whose type takes this page's type; never the page itself or its descendants.
	const own = await subtreeIds(cms.config.db, page.id);
	const parents = (await site.pages.query().selectAll("tbtop_pages").orderBy("sort_order").orderBy("id").execute()).filter(
		(row) => !own.has(Number(row.id)) && (row.type === "category" || allowsChild(cms.pageTypes, row.type, page.type)),
	);
	const unlessPublished = Cond.neq("status", "published");
	return s.stack([
		s.section({ title: "Publication", variant: "card" }, [
			s.select("status").label("Status").options(STATUS).required().in(["draft", "published"]),
			s.boolean("is_indexable").label("Indexable in search"),
			s.datetime("published_at").label("Publish at").helperText("Leave empty to publish immediately.").hiddenIf(unlessPublished),
			s.datetime("unpublish_at").label("Unpublish at").helperText("Leave empty to keep published indefinitely.").hiddenIf(unlessPublished),
		]),
		s.section({ title: "Structure", variant: "card" }, [
			...typeSelects(s, cms, page),
			s.select("parent_id").label("Parent").options(parentOptions(parents, defaultLocale)),
		]),
		s.section({ title: "Ordering", variant: "card" }, [s.number("sort_order").label("Sort order").rules("nullable|integer|min:0")]),
		s.section({ title: "Image", variant: "card" }, [s.upload("image").label("Image").accept("image/*")]),
	]);
}

/**
 * The type select and the stored base's subtype select; the subtype stays
 * until cleared, since a type change that leaves one behind is refused. A
 * system page keeps its type.
 */
function typeSelects(s: S, cms: Cms, page: Page): unknown[] {
	if (page.isSystem) {
		return [];
	}
	const types = cms.pageTypes.bases().map((type) => ({ value: type.key, label: type.label }));
	const subtypes = subtypeOptions(cms.pageTypes, page.type);
	return [
		s.select("type").label("Type").options(types).required(),
		...(subtypes.length === 0
			? []
			: [s.select("subtype").label("Subtype").options(subtypes).default("").hiddenIf(Cond.all(Cond.neq("type", page.type), Cond.empty("subtype")))]),
	];
}

/** PageEditPage::canDelete: no button for a page that would refuse to go. */
async function canDelete(site: Site, page: Page): Promise<boolean> {
	return !page.isSystem && (await site.pages.query().select("id").where("parent_id", "=", page.id).executeTakeFirst()) === undefined;
}

function deleteAction(s: S, cms: Cms, page: Page) {
	return s
		.action("pageEditDelete")
		.label("Delete")
		.color("danger")
		.confirm("Delete page?", "The page goes to the trash and its URL stops working.")
		.handle(async (ctx) => {
			try {
				await siteOf(cms, ctx).pages.delete(page.id);
			} catch (error) {
				if (error instanceof PageRuleError) {
					return Effects.make().notify(error.message, "error");
				}
				throw error;
			}
			return Effects.make().notify("Page deleted").redirect(`${adminPrefix(cms)}/pages`);
		}, []);
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

/** PageForm::generalFields: the site root keeps its empty slug, so a system page has no slug field. */
function generalFields(s: S, page: Page): unknown[] {
	return [
		s.text("name").label("Name").required().translatable().helperText("Short name for the admin lists and tree; the site uses it as the breadcrumb and link label."),
		s.text("title").label("Title").translatable().helperText("Browser-tab and SEO title — falls back to Name when left empty."),
		...(page.isSystem
			? []
			: [
					s
						.slug("slug")
						.label("Slug")
						.translatable()
						.fromField("name")
						.helperText("Changing the slug changes the page URL; an automatic redirect is created from the old address."),
				]),
		s.text("heading").label("Heading").translatable().helperText("The H1 shown on the page itself."),
		s.textarea("summary").label("Summary").translatable(),
		s.richtext("body").label("Content").translatable(),
	];
}

/**
 * PageSeoFields: meta title and description with what a blank one resolves
 * to as the placeholder (default locale), the Social networks section, the
 * canonical override and, for a type with several, the schema kind.
 */
async function seoFields(s: S, cms: Cms, site: Site, page: Page, locale: string): Promise<unknown[]> {
	const ancestors = () => ancestorCrumbs(site, page, locale);
	const title = await renderedTitle(site, page, locale, ancestors);
	const description = await renderedDescription(site, page, locale, ancestors);
	const schemaKeys = cms.pageTypes.has(page.type) ? cms.pageTypes.schemaTypesFor(page).filter((key) => cms.schemaTypes.has(key)) : [];
	return [
		s.text("seo_title").label("Meta title").translatable().placeholder(title).rules("nullable|max:255").helperText("The clickable headline in search results."),
		s.textarea("seo_description").label("Meta description").translatable().placeholder(description).helperText("The snippet under the headline in search results."),
		s.section({ title: "Social networks" }, [
			s.text("og_title").label("OG title").translatable().placeholder(title),
			s.textarea("og_description").label("OG description").translatable().placeholder(description),
			s.upload("og_image").label("OG image").accept("image/*"),
		]),
		s.text("seo_canonical").label("Canonical URL override").translatable().rules("nullable|url").helperText("Advanced use only."),
		...(schemaKeys.length > 1
			? [
					s.section({ title: "Structured data" }, [
						s
							.select("schema_type")
							.label("Structured data kind")
							.options(schemaKeys.map((key) => ({ value: key, label: cms.schemaTypes.get(key).label ?? key }))),
					]),
				]
			: []),
	];
}

function seoRecord(cms: Cms, page: Page): Dict {
	const seo = page.seo;
	const schema = isDict(seo.schema) ? seo.schema : {};
	const keys = cms.pageTypes.has(page.type) ? cms.pageTypes.schemaTypesFor(page) : [];
	return {
		seo_title: seo.title ?? null,
		seo_description: seo.description ?? null,
		og_title: seo.og_title ?? null,
		og_description: seo.og_description ?? null,
		og_image: typeof seo.og_image === "string" && seo.og_image !== "" ? seo.og_image : null,
		seo_canonical: seo.canonical ?? null,
		// PageForm::schemaRow: the effective kind, so a page saved before the key existed opens on its type's default.
		schema_type: typeof schema.type === "string" && keys.includes(schema.type) ? schema.type : (keys[0] ?? null),
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
 * PageFormWriter::save for the editor: columns, seo blob, type fields, block
 * rows, slugs; optimistic lock on lock_version. A `structural` save (add or
 * remove a block) writes the stored status, never the form's.
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
	const fields = foldTypeFields(cms, page, form);
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
			...(fields === null ? {} : { fields }),
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
