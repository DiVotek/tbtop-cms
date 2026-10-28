import { Column, definePage, Effects, Field, type HostRule, HttpError, type PageDef, S } from "@tbtop/admin";
import { bindCmsFields } from "../blocks/cmsFields";
import { COLLECTION_VALUE_RULE, entryIds, PICK_MODES, type PickMode } from "../blocks/collectionField";
import type { Cms } from "../cms";
import type { Collection } from "../content/collections";
import { ENTRY_DRAFT, ENTRY_PUBLISHED, type Entry, EntryConflictError, type EntryStatus } from "../content/entries";
import { type Dict, isDict } from "../support/values";
import { gated } from "./abilities";
import { collectionBaseUrl, collectionListUrl, ENTRY_SEARCH_FIELD, fieldBinding } from "./linkFields";
import { editFooter, editorValues, listHeader, NAV, recordCrumbs, siteOf } from "./support";

const SEARCH_LIMIT = 20;

const STATUS_OPTIONS = [
	{ value: ENTRY_PUBLISHED, label: "Published" },
	{ value: ENTRY_DRAFT, label: "Draft" },
];

export const entryEditUrl = (cms: Cms, key: string, id: number | string): string => `${collectionBaseUrl(cms, key)}/${String(id)}/edit`;

/** The `name` of the Collections page's tabs block: the open tab is `?tab[collections]={key}`. */
export const COLLECTION_TABS = "collections";

/**
 * The admin pages of the collections, behind the pages ability: one
 * Collections page (a tab each, one menu item), and per collection the entry
 * editor and the entry search its `collection` fields use.
 */
export function collectionPages(cms: Cms): PageDef[] {
	return [
		gated(cms, "tbtop-cms.pages", collectionsIndex(cms)),
		...cms.collections.all().flatMap((collection) => [
			gated(cms, "tbtop-cms.pages", entryEdit(cms, collection)),
			gated(cms, "tbtop-cms.pages", entrySearch(cms, collection)),
		]),
	] as PageDef[];
}

/** The entry form: the collection's fields, bound to the CMS lookups, then the status. */
function entryFields(s: S, cms: Cms, collection: Collection): unknown[] {
	return [
		...bindCmsFields(collection.form(s), fieldBinding(cms)),
		s.select("status").label("Status").options(STATUS_OPTIONS).required(),
	];
}

const fieldsOf = (collection: Collection): Field[] => collection.form(new S()).filter((field): field is Field => field instanceof Field);

/** The submitted values of the collection's own fields. */
function dataOf(collection: Collection, form: Dict): Dict {
	return Object.fromEntries(collection.fieldNames.filter((name) => name in form).map((name) => [name, form[name]]));
}

const statusOf = (value: unknown): EntryStatus => (value === ENTRY_PUBLISHED ? ENTRY_PUBLISHED : ENTRY_DRAFT);

/** The row's delete, scoped to the collection. */
function deleteAction(s: S, cms: Cms, key: string, table: string) {
	return s
		.action(`${table}Delete`)
		.label("Delete")
		.color("danger")
		.confirm("Delete this entry?", "Blocks stop showing it; this cannot be undone.")
		.handle(async (ctx) => {
			const entry = await siteOf(cms, ctx).entries.find(Number(ctx.key()));
			if (entry !== null && entry.collection === key) {
				await siteOf(cms, ctx).entries.delete(entry.id);
			}
			return Effects.make().notify("Deleted").refreshTable(table);
		}, ["row"]);
}

/**
 * CollectionEntriesPage: every collection as a tab, the built-ins first; each
 * tab has its entries table (search, drag reorder, delete) and an "Add" modal.
 */
function collectionsIndex(cms: Cms) {
	return definePage({
		path: "collections",
		...listHeader("content", "Collections"),
		nav: { group: NAV.content, label: "Collections", icon: "library", order: 15 },
		view: (s, { request }) => {
			const open = new URL(request.url).searchParams.get(`tab[${COLLECTION_TABS}]`);
			const tabs = cms.collections.all().map((collection) => ({
				name: collection.key,
				label: collection.label,
				active: collection.key === open,
				children: entriesTab(s, cms, collection, request),
			}));
			return s.tabs(tabs, { name: COLLECTION_TABS });
		},
	});
}

/** One collection's tab: its "Add" modal and its entries table. */
function entriesTab(s: S, cms: Cms, collection: Collection, request: Request): unknown[] {
	const { key } = collection;
	const suffix = `_${key.replaceAll("-", "_")}`;
	const table = `entries${suffix}`;
	const title = `Add ${collection.singularLabel}`;
	return [
		s.flex(
			[
				s.spacer(),
				s
					.action(`entryCreate${suffix}`)
					.label(title)
					.icon("plus")
					.color("primary")
					.modal(
						title,
						s
							.form(`entryCreate${suffix}`, [...entryFields(s, cms, collection), s.actionsRow([s.action(`entryCreateSave${suffix}`).label("Create").color("primary").submit()])])
							.record({ status: ENTRY_PUBLISHED })
							.onSubmit(async (ctx) => {
								const form = ctx.form as Dict;
								const id = await siteOf(cms, ctx).entries.create({ collection: key, data: dataOf(collection, form), status: statusOf(form.status) });
								return entryEditUrl(cms, key, id);
							}),
					),
			],
			{ justify: "end" },
		),
		s
			.table(table)
			.columns([
				Column.make("title")
					.label("Name")
					.emphasized()
					.searchable()
					.formatUsing((value, row) => (typeof value === "string" && value !== "" ? value : `#${String(row.id)}`)),
				Column.make("status").label("Status").badge({ published: { color: "success", label: "Published" }, draft: { color: "gray", label: "Draft" } }),
				Column.make("updated_at").label("Updated").datetime(),
			])
			.reorderable("sort_order")
			.recordUrl((row) => entryEditUrl(cms, key, String(row.id)))
			.emptyState("No entries yet", "Add the first one; blocks show the published entries.")
			.rowActions([deleteAction(s, cms, key, table)])
			.query(() => cms.site(request).config.db.selectFrom("tbtop_entries").where("collection", "=", key)),
	];
}

/** CollectionEntryEditPage: the entry's fields and status under the optimistic lock. */
function entryEdit(cms: Cms, collection: Collection) {
	const { key, label } = collection;
	const listUrl = collectionListUrl(cms, key);
	const find = async (cmsSite: ReturnType<Cms["site"]>, id: string): Promise<Entry> => {
		const entry = /^\d+$/.test(id) ? await cmsSite.entries.find(Number(id)) : null;
		if (entry === null || entry.collection !== key) {
			throw new HttpError(404, "Entry not found.");
		}
		return entry;
	};
	return definePage({
		path: `collections/${key}/$entry/edit`,
		title: label,
		breadcrumbs: [...recordCrumbs("content", { label: "Collections", url: listUrl }, label), { label: "Edit" }],
		view: async (s, { params, request }) => {
			const entry = await find(cms.site(request), params.entry ?? "");
			return s
				.form("entryEditor", [
					s.number("lock_version").meta("hidden", true),
					...entryFields(s, cms, collection),
					editFooter(s, listUrl, s.action("save").label("Save").color("primary").keybinding("mod+s").submit()),
				])
				.record({ ...editorValues(fieldsOf(collection), entry.data), status: entry.status, lock_version: entry.lockVersion })
				.onSubmit(async (ctx) => {
					const form = ctx.form as Dict;
					const lockVersion = Number(form.lock_version ?? entry.lockVersion);
					try {
						await siteOf(cms, ctx).entries.update(entry.id, { data: dataOf(collection, form), status: statusOf(form.status) }, { lockVersion });
					} catch (error) {
						if (error instanceof EntryConflictError) {
							return Effects.make().notify(
								"This entry was changed by someone else while you were editing, so your unsaved edits were not applied. Reload to see the latest version.",
								"error",
							);
						}
						throw error;
					}
					return Effects.make().notify("Saved");
				});
		},
	});
}

/**
 * EntrySearchController as an admin page, so the panel's user check guards
 * it: `{search}` matches the title (drafts marked), `{values}` names picked
 * ids in the order given.
 */
function entrySearch(cms: Cms, collection: Collection) {
	const { key } = collection;
	const option = (entry: Entry) => ({ value: String(entry.id), label: optionLabel(entry) });
	return definePage({
		path: `collections/${key}/entry-search`,
		title: `${collection.label}: search`,
		mcp: false,
		view: (s, { request }) => {
			const site = cms.site(request);
			const field = s
				.select(ENTRY_SEARCH_FIELD)
				.label(collection.singularLabel)
				.searchable()
				.query(async (_deps, search) => {
					const needle = search.trim().toLocaleLowerCase();
					const entries = await site.entries.list(key);
					return entries
						.filter((entry) => needle === "" || entry.title.toLocaleLowerCase().includes(needle))
						.slice(0, SEARCH_LIMIT)
						.map(option);
				})
				.resolveUsing(async (value) => {
					const entry = /^\d+$/.test(String(value)) ? await site.entries.find(Number(value)) : null;
					return entry === null || entry.collection !== key ? null : optionLabel(entry);
				});
			return s.form("entrySearch", [field]);
		},
	});
}

const optionLabel = (entry: Entry): string => `${entry.title === "" ? `#${entry.id}` : entry.title}${entry.status === ENTRY_DRAFT ? " (draft)" : ""}`;

/**
 * CollectionValueValidator: `collection_value:{key},{cap|0},{mode+mode}`.
 * Empty passes; the mode must be allowed, a latest-N count within the cap,
 * hand-picked ids entries of that collection (drafts allowed) within the cap.
 */
export async function collectionValueValid(cms: Cms, value: unknown, params: string[]): Promise<boolean> {
	if (value === null || value === undefined || (Array.isArray(value) && value.length === 0)) {
		return true;
	}
	if (!isDict(value) || typeof value.mode !== "string") {
		return false;
	}
	const [key = "", rawCap = "0", modes = ""] = params;
	const cap = Number(rawCap) || 0;
	const mode = value.mode as PickMode;
	if (!(PICK_MODES as readonly string[]).includes(mode) || !modes.split("+").includes(mode)) {
		return false;
	}
	if (mode === "latest") {
		const limit = value.limit;
		if (limit === null || limit === undefined) {
			return true;
		}
		const n = typeof limit === "number" ? limit : typeof limit === "string" && /^\d+$/.test(limit) ? Number(limit) : Number.NaN;
		return Number.isInteger(n) && n >= 1 && (cap === 0 || n <= cap);
	}
	if (mode !== "manual" || value.ids === null || value.ids === undefined) {
		return true;
	}
	if (!Array.isArray(value.ids)) {
		return false;
	}
	const ids = entryIds(value.ids);
	if (ids.length !== value.ids.length || (cap !== 0 && ids.length > cap)) {
		return false;
	}
	if (ids.length === 0) {
		return true;
	}
	const found = await cms.config.db
		.selectFrom("tbtop_entries")
		.select((eb) => eb.fn.countAll().as("n"))
		.where("collection", "=", key)
		.where(
			"id",
			"in",
			ids.map((id) => Number(id)),
		)
		.executeTakeFirst();
	return Number(found?.n ?? 0) === ids.length;
}

/** `collection_value` (CollectionValueValidator), for createAdmin({ hostRules }) next to cmsHostRules. */
export function collectionHostRules(cms: Cms): Record<string, HostRule> {
	return {
		[COLLECTION_VALUE_RULE]: {
			message: "This selection is not valid.",
			check: (value, params) => collectionValueValid(cms, value, params),
		},
	};
}
