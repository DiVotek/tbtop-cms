import { Column, definePage, Effects, Field, HttpError, type PageDef, S } from "@tbtop/admin";
import { bindCmsFields } from "../blocks/cmsFields";
import { COLLECTION_VALUE_RULE, entryIds, PICK_MODES, type PickMode } from "../blocks/collectionField";
import type { Cms } from "../cms";
import type { Collection } from "../content/collections";
import { ENTRY_DRAFT, ENTRY_PUBLISHED, type Entry, EntryConflictError, type EntryStatus } from "../content/entries";
import { type Dict, isDict } from "../support/values";
import { gated } from "./abilities";
import { fieldBinding } from "./linkFields";
import { adminPrefix, editFooter, editorValues, siteOf } from "./support";

const SEARCH_FIELD = "entry";
const SEARCH_LIMIT = 20;

const STATUS_OPTIONS = [
	{ value: ENTRY_PUBLISHED, label: "Published" },
	{ value: ENTRY_DRAFT, label: "Draft" },
];

const GROUP_LABELS: Record<string, string> = { content: "Content", design: "Design", settings: "Settings" };

export const collectionListUrl = (cms: Cms, key: string): string => `${adminPrefix(cms)}/collections/${key}`;
export const entryEditUrl = (cms: Cms, key: string, id: number | string): string => `${collectionListUrl(cms, key)}/${String(id)}/edit`;
/** The picker of a `collection` field posts `{search}` / `{values}` here (EntrySearchRoute). */
export const entrySearchEndpoint = (cms: Cms, key: string): string => `${collectionListUrl(cms, key)}/entry-search/select-options/${SEARCH_FIELD}`;

/**
 * Each collection's admin pages, behind the pages ability: its list (menu
 * item), the entry editor and the entry search its `collection` fields use.
 */
export function collectionPages(cms: Cms): PageDef[] {
	return cms.collections.all().flatMap((collection) => [
		gated(cms, "tbtop-cms.pages", entriesIndex(cms, collection)),
		gated(cms, "tbtop-cms.pages", entryEdit(cms, collection)),
		gated(cms, "tbtop-cms.pages", entrySearch(cms, collection)),
	]) as PageDef[];
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

/** CollectionEntriesPage: the entries table (search, drag reorder, delete) and the "Add" modal. */
function entriesIndex(cms: Cms, collection: Collection) {
	const { key, label } = collection;
	const table = `entries_${key.replaceAll("-", "_")}`;
	return definePage({
		path: `collections/${key}`,
		title: label,
		breadcrumbs: [{ label: GROUP_LABELS[collection.navigationGroup] ?? collection.navigationGroup }, { label }],
		nav: { group: collection.navigationGroup, label, icon: collection.icon, order: collection.navigationOrder },
		view: (s, { request }) =>
			s.stack([
				s.row([
					s
						.action("entryCreate")
						.label(`Add ${collection.singularLabel}`)
						.icon("plus")
						.color("primary")
						.modal(
							`Add ${collection.singularLabel}`,
							s
								.form("entryCreate", [...entryFields(s, cms, collection), s.actionsRow([s.action("entryCreateSave").label("Create").color("primary").submit()])])
								.record({ status: ENTRY_PUBLISHED })
								.onSubmit(async (ctx) => {
									const form = ctx.form as Dict;
									const id = await siteOf(cms, ctx).entries.create({ collection: key, data: dataOf(collection, form), status: statusOf(form.status) });
									return entryEditUrl(cms, key, id);
								}),
						),
				]),
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
					.rowActions([
						s
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
							}, ["row"]),
					])
					.query(() => cms.site(request).config.db.selectFrom("tbtop_entries").where("collection", "=", key)),
			]),
	});
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
		breadcrumbs: [{ label: GROUP_LABELS[collection.navigationGroup] ?? collection.navigationGroup }, { label, url: listUrl }, { label: "Edit" }],
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
				.select(SEARCH_FIELD)
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

export { COLLECTION_VALUE_RULE };
