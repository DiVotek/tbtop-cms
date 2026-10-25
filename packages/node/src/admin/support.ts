import { type ActionCtx, Effects, Field, S } from "@tbtop/admin";
import { bindCmsFields } from "../blocks/cmsFields";
import type { BlockRegistry } from "../blocks/registry";
import { placeableIn } from "../blocks/catalog";
import { rowProblem } from "../blocks/resolver";
import type { Cms } from "../cms";
import type { SharedBlock } from "../content/sharedBlocks";
import type { Site } from "../site";
import { type Dict, isDict } from "../support/values";
import { fieldBinding } from "./linkFields";

export const NAV = { content: "content", design: "design", settings: "settings" } as const;

export const adminPrefix = (cms: Cms): string => `/${(cms.config.adminPrefix ?? "admin").replace(/^\/+|\/+$/g, "")}`;

/** A page action's per-request Site, from the action's request. */
export const siteOf = (cms: Cms, ctx: { request: Request }): Site => cms.site(ctx.request);

/** A fresh block id: the PHP editor's 8-character random ids. */
export const blockId = (): string => crypto.randomUUID().replaceAll("-", "").slice(0, 8);

/** Stored block rows that carry an id and a kind, in `sort` order (PageEditBlocks::blocks). */
export function blockRows(content: unknown[] | null): Dict[] {
	return (content ?? [])
		.map((row, index) => ({ row, index }))
		.filter((entry): entry is { row: Dict; index: number } => isDict(entry.row) && typeof entry.row.id === "string" && typeof entry.row.kind === "string")
		.map(({ row, index }) => ({ ...row, sort: Number.isInteger(row.sort) ? (row.sort as number) : index }))
		.sort((a, b) => (a.sort as number) - (b.sort as number));
}

/**
 * The block's form with every top-level field renamed `block_{id}_{name}`
 * (PrefixedField::rename), so many blocks share one editor form. With
 * `activeField`, each `required` (field-level and per locale) becomes
 * `required_unless:{activeField},false,0`: an inactive block never renders,
 * so its empty fields must not veto a save. The label keeps its marker.
 */
export function prefixedForm(blocks: BlockRegistry, kind: string, id: string, s: S, activeField?: string): unknown[] {
	return blocks.get(kind).form(s).map((field) => {
		if (field instanceof Field) {
			Object.defineProperty(field, "name", { value: `block_${id}_${field.name}` });
			if (activeField !== undefined) {
				gateRequired(field, `required_unless:${activeField},false,0`);
			}
		}
		return field;
	});
}

/** PrefixedField::gateRules: swaps `required` for `rule` in the field's rules and each locale's. */
function gateRequired(field: Field, rule: string): void {
	const swap = (rules: string[]) => rules.map((entry) => (entry === "required" ? rule : entry));
	(field as unknown as { replaceRules(rules: string[]): void }).replaceRules(swap(field.ruleEntries()));
	for (const [locale, rules] of Object.entries(field.localeRuleEntries())) {
		field.rulesForLocale(locale, swap(rules));
	}
}

const formFields = (blocks: BlockRegistry, kind: string): Field[] =>
	blocks
		.get(kind)
		.form(new S())
		.filter((field): field is Field => field instanceof Field);

const fieldNames = (blocks: BlockRegistry, kind: string): string[] => formFields(blocks, kind).map((field) => field.name);

/** A block's stored data as editor values, by field name: what the form has a field for, lists translated whole made per-cell. */
export function editorValues(fields: Field[], data: Dict): Dict {
	return Object.fromEntries(fields.filter((field) => field.name in data).map((field) => [field.name, editorValue(field, data[field.name])]));
}

/**
 * The frontend reads a repeater stored as one list per locale
 * (`{ uk: [...], en: [...] }`) as well as one list with translated cells;
 * the editor edits only the second. A per-locale value becomes per-cell,
 * row by row: translatable sub-fields keep every locale's value, the others
 * take the first locale's. The next save stores it that way.
 */
export function editorValue(field: Field, value: unknown): unknown {
	if (field.kind !== "repeater") {
		return value;
	}
	const children = field.childFields().filter((child): child is Field => child instanceof Field);
	const rows = perLocaleRows(children, value) ?? value;
	return Array.isArray(rows) ? rows.map((row) => (isDict(row) ? { ...row, ...editorValues(children, row) } : row)) : rows;
}

/** `{ locale: rows }` merged row by row; null for anything else. */
function perLocaleRows(children: Field[], value: unknown): Dict[] | null {
	if (!isDict(value) || Object.keys(value).length === 0 || !Object.values(value).every(Array.isArray)) {
		return null;
	}
	const lists = value as Record<string, unknown[]>;
	const length = Math.max(...Object.values(lists).map((list) => list.length));
	return Array.from({ length }, (_, index) => mergeRow(children, lists, index));
}

function mergeRow(children: Field[], perLocale: Record<string, unknown[]>, index: number): Dict {
	const row: Dict = {};
	for (const child of children) {
		const byLocale = Object.entries(perLocale)
			.map(([locale, list]) => [locale, list[index]] as const)
			.filter((entry): entry is readonly [string, Dict] => isDict(entry[1]) && child.name in entry[1])
			.map(([locale, item]) => [locale, item[child.name]] as const);
		if (byLocale.length === 0) {
			continue;
		}
		row[child.name] = child.isTranslatableField() ? Object.fromEntries(byLocale) : byLocale[0]?.[1];
	}
	return row;
}

/** Form values of the editable rows: `block_{id}_{field}`, `_active`, `_sort` (PageEditBlockTabs::recordValues). */
export function blockRecord(blocks: BlockRegistry, rows: Dict[]): Dict {
	const out: Dict = {};
	for (const row of rows) {
		const id = row.id as string;
		out[`block_${id}_active`] = row.active !== false;
		out[`block_${id}_sort`] = row.sort;
		if (!blocks.has(row.kind as string) || row.shared !== undefined) {
			continue;
		}
		const values = editorValues(formFields(blocks, row.kind as string), isDict(row.data) ? row.data : {});
		for (const [name, value] of Object.entries(values)) {
			out[`block_${id}_${name}`] = value;
		}
	}
	return out;
}

/** The rows back from the submitted form; unknown kinds and shared rows keep their stored data (PageEditBlocks::toRows). */
export function rowsFromForm(blocks: BlockRegistry, rows: Dict[], form: Dict): Dict[] {
	return rows
		.map((row) => {
			const id = row.id as string;
			const kind = row.kind as string;
			const sortValue = Number(form[`block_${id}_sort`]);
			const base: Dict = {
				id,
				kind,
				active: `block_${id}_active` in form ? Boolean(form[`block_${id}_active`]) : row.active !== false,
				sort: Number.isFinite(sortValue) && form[`block_${id}_sort`] !== null && form[`block_${id}_sort`] !== "" ? Math.trunc(sortValue) : row.sort,
			};
			if (row.shared !== undefined) {
				return { ...base, shared: row.shared } as Dict;
			}
			if (!blocks.has(kind)) {
				return { ...base, data: isDict(row.data) ? row.data : {} } as Dict;
			}
			const data: Dict = {};
			for (const name of fieldNames(blocks, kind)) {
				const key = `block_${id}_${name}`;
				if (key in form) {
					data[name] = form[key];
				}
			}
			return { ...base, data } as Dict;
		})
		.sort((a, b) => (a.sort as number) - (b.sort as number));
}

export type BlockTabsOptions = {
	/** Where the list lives: `["layout"]`, or a page's type and its subtype, which picks the kinds the Add tab offers. */
	placement: string[];
	/** Persists a structural change (add, remove) outside the editor's Save; an Effects (an error notice) answers instead of `back`. */
	save: (ctx: ActionCtx, rows: Dict[]) => Promise<Effects | void>;
	back: string;
	/**
	 * Structural actions skip the form rules (PageEditBlockTabs::skipsGate): only
	 * on a draft page, which cannot leak. A published page or a layout validates first.
	 */
	skipsValidation: boolean;
	/** Every shared block: a reference tab names its source and detaches from it. */
	sharedBlocks?: SharedBlock[];
	/** Rows the site skips, by id (rowProblems): listed under Problem blocks instead of a tab. */
	problems?: Map<string, string>;
	/**
	 * Set when the editor may edit shared blocks (the design ability): the add
	 * tab offers the shared blocks of a kind by reference, every inline tab
	 * gets a "Make shared" action that names the new shared block
	 * "{label} — {owner}", and a reference tab links to its source.
	 */
	sharing?: { owner: string };
};

type EditorTab = { label: string; badge?: string; body: unknown };

/**
 * The editor tabs of a block list (PageEditBlockTabs::build): one per row
 * (its fields, active, sort, remove) plus an "Add block" tab. `save(rows)`
 * persists a structural change.
 */
export function blockTabs(s: S, cms: Cms, rows: Dict[], options: BlockTabsOptions): EditorTab[] {
	const blocks = cms.config.blocks;
	const tabs: EditorTab[] = [];
	const problems = options.problems ?? new Map<string, string>();
	const sources = new Map((options.sharedBlocks ?? []).map((block) => [block.id, block]));
	for (const row of rows) {
		const id = row.id as string;
		const kind = row.kind as string;
		// A row the site skips has no tab: the Problem blocks tab lists it (PageEditBlockTabs::build).
		if (!blocks.has(kind) || problems.has(id)) {
			continue;
		}
		const shared = typeof row.shared === "number" || typeof row.shared === "string" ? Number(row.shared) : null;
		const tab = shared === null ? inlineTab(s, cms, rows, row, options) : sharedTab(s, cms, rows, row, sources.get(shared) ?? null, shared, options);
		tabs.push({ ...tab, ...(row.active === false ? { badge: "·" } : {}) });
	}
	if (problems.size > 0) {
		tabs.push({ label: "Problem blocks", badge: String(problems.size), body: problemsBody(s, cms, rows, problems, options) });
	}
	tabs.push({ label: "+ Add block", body: addBlockBody(s, cms, rows, options) });
	return tabs;
}

/** The active switch and the order number, side by side under the block's fields. */
const rowControls = (s: S, id: string) =>
	s.flex([s.boolean(`block_${id}_active`).label("Active"), s.number(`block_${id}_sort`).label("Order").rules("nullable|integer")], { gap: 4, align: "end" });

function inlineTab(s: S, cms: Cms, rows: Dict[], row: Dict, options: BlockTabsOptions): EditorTab {
	const id = row.id as string;
	const kind = row.kind as string;
	const blocks = cms.config.blocks;
	const body = s.stack([
		...bindCmsFields(prefixedForm(blocks, kind, id, s, `block_${id}_active`), fieldBinding(cms)),
		rowControls(s, id),
		s.flex([removeAction(s, cms, rows, id, options), s.spacer(), ...(options.sharing === undefined ? [] : [shareAction(s, cms, rows, id, options)])]),
	]);
	return { label: blocks.get(kind).label, body };
}

/**
 * PageEditBlockTabs::sharedTab: a reference has no fields, the content lives
 * on the shared block; a link to edit it there, and "Detach", which copies
 * the shared content into this row so the page can diverge.
 */
function sharedTab(s: S, cms: Cms, rows: Dict[], row: Dict, source: SharedBlock | null, sharedId: number, options: BlockTabsOptions): EditorTab {
	const id = row.id as string;
	const label = cms.config.blocks.get(row.kind as string).label;
	const name = source?.name ?? `#${sharedId}`;
	const body = s.stack([
		s.displayText(`This block shows the shared block “${name}”. Edit the content there — every page that places it changes together.`).variant("muted"),
		s.flex(
			[
				...(options.sharing === undefined ? [] : [s.action(`blockEditShared_${id}`).label("Edit shared block").url(`${adminPrefix(cms)}/shared-blocks/${sharedId}/edit`)]),
				...(source === null ? [] : [detachAction(s, cms, rows, id, source, options)]),
			],
			{ gap: 2 },
		),
		rowControls(s, id),
		s.flex([removeAction(s, cms, rows, id, options), s.spacer()]),
	]);
	// A shared block named after its kind ("CTA banner" of kind CTA banner) would read the name twice.
	return { label: name === label ? label : `${label} · ${name}`, body };
}

/** PageEditBlockTabs::handleDetach: the row keeps its id, order and active flag; the pointer becomes a copy of the content. */
function detachAction(s: S, cms: Cms, rows: Dict[], id: string, source: SharedBlock, options: BlockTabsOptions) {
	return s
		.action(`blockDetach_${id}`)
		.label("Detach")
		.confirm("Detach from the shared block?", `The current content of “${source.name}” is copied here. Later edits to the shared block will no longer reach it.`)
		.withoutValidation(options.skipsValidation)
		.handle(async (ctx) => {
			const current = rowsFromForm(cms.config.blocks, rows, ctx.form as Dict).map((entry) => {
				if (entry.id !== id) {
					return entry;
				}
				const { shared: _shared, ...rest } = entry;
				return { ...rest, data: structuredClone(source.data) };
			});
			const refused = await options.save(ctx, current);
			return refused ?? Effects.make().notify("Block detached — it now has its own content").redirect(options.back);
		}, ["form"]);
}

/** BlockRowProblem for each stored row the site skips (an unregistered kind, a missing or mismatched shared block), by row id. */
export async function rowProblems(site: Site, rows: Dict[]): Promise<Map<string, string>> {
	const out = new Map<string, string>();
	for (const row of rows) {
		const problem = await rowProblem(site, row, row.kind as string);
		if (problem !== null) {
			out.set(row.id as string, problem);
		}
	}
	return out;
}

/** The Problem blocks list (ProblemBlocksAction): what is wrong with each row, and a remove that saves without it. */
function problemsBody(s: S, cms: Cms, rows: Dict[], problems: Map<string, string>, options: BlockTabsOptions): unknown {
	return s.stack([
		s.displayText("The site skips these blocks. Remove them, or register their kind again.").variant("muted"),
		...[...problems].map(([id, problem]) => s.row([s.displayText(problem), removeAction(s, cms, rows, id, options)])),
	]);
}

function removeAction(s: S, cms: Cms, rows: Dict[], id: string, options: BlockTabsOptions) {
	return s
		.action(`blockRemove_${id}`)
		.label("Remove block")
		.color("danger")
		.confirm("Remove block?", "This cannot be undone.")
		.withoutValidation(options.skipsValidation)
		.handle(async (ctx) => {
			const kept = rowsFromForm(cms.config.blocks, rows, ctx.form as Dict).filter((row) => row.id !== id);
			return (await options.save(ctx, kept)) ?? Effects.make().notify("Block removed").redirect(options.back);
		}, ["form"]);
}

/**
 * BlockPicker: a card per placeable kind (grouped by category when there are
 * several), each with its own Add. A kind with shared blocks asks first:
 * one of them by reference, or a new inline one (addChoiceAction).
 */
function addBlockBody(s: S, cms: Cms, rows: Dict[], options: BlockTabsOptions): unknown {
	const kinds = cms.config.blocks.all().filter((block) => placeableIn(block.placements, options.placement));
	if (kinds.length === 0) {
		return s.displayText("No block kinds are registered for this place.").variant("muted");
	}
	const groups = new Map<string, typeof kinds>();
	for (const block of kinds) {
		const key = block.category ?? "";
		groups.set(key, [...(groups.get(key) ?? []), block]);
	}
	// Uncategorized kinds go last, as BlockPicker::groups puts them.
	const ordered = [...groups].sort(([a], [b]) => Number(a === "") - Number(b === ""));
	return s.stack(
		ordered.map(([category, group]) => {
			const grid = s.grid({ cols: { md: 2, xl: 3 }, gap: 3 }, group.map((block) => pickerCard(s, block, addFor(s, cms, rows, block.kind, options))));
			return ordered.length > 1 ? s.section({ title: category === "" ? "Other" : category, variant: "plain" }, [grid]) : grid;
		}),
	);
}

function pickerCard(s: S, block: { label: string; description: string | null }, action: unknown): unknown {
	return s.flex(
		[
			s.flex([s.displayText(block.label).variant("subheading"), s.spacer(), action], { align: "center", gap: 2 }),
			...(block.description === null ? [] : [s.displayText(block.description).variant("muted")]),
		],
		{ direction: "col", gap: 2, variant: "card" },
	);
}

/** The card's Add: inline at once, or the choice modal for a kind with shared blocks. */
function addFor(s: S, cms: Cms, rows: Dict[], kind: string, options: BlockTabsOptions) {
	const shared = options.sharing === undefined ? [] : (options.sharedBlocks ?? []).filter((block) => block.kind === kind).sort((a, b) => a.name.localeCompare(b.name));
	if (shared.length === 0) {
		return addAction(s, cms, rows, kind, null, options);
	}
	const body = s.stack([
		s.displayText("This kind has shared blocks. Place one by reference — it is edited in one place for every page — or add a fresh block with its own content.").variant("muted"),
		s.stack(
			shared.map((block) =>
				s.flex([s.displayText(block.name).variant("subheading"), s.spacer(), addAction(s, cms, rows, kind, block.id, options)], { align: "center", gap: 2, variant: "card" }),
			),
			{ gap: 2 },
		),
		s.flex([s.spacer(), addAction(s, cms, rows, kind, null, options).label("Add a new one").outlined()]),
	]);
	return s.action(`blockChoose_${kind}`).label("Add").modal(`Add «${cms.config.blocks.get(kind).label}»`, body);
}

/**
 * PageEditBlockTabs::handleAdd: the new row goes last, saved inactive. The
 * action writes outside Save and a layout has no draft, so an empty block
 * would otherwise go live at once; the editor turns it on once it is filled
 * in. A shared reference is configured already, but the same rule keeps
 * "add" from publishing.
 */
function addAction(s: S, cms: Cms, rows: Dict[], kind: string, sharedId: number | null, options: BlockTabsOptions) {
	return s
		.action(sharedId === null ? `blockAdd_${kind}` : `blockAddShared_${sharedId}`)
		.label("Add")
		.withoutValidation(options.skipsValidation)
		.handle(async (ctx) => {
			// The view registers only placeable kinds; the handler re-checks so a stale registration cannot write another.
			if (!cms.config.blocks.has(kind) || !placeableIn(cms.config.blocks.get(kind).placements, options.placement)) {
				return Effects.make().notify("Pick a block kind first.", "error");
			}
			const current = rowsFromForm(cms.config.blocks, rows, ctx.form as Dict);
			const sort =Math.max(-1, ...current.map((entry) => entry.sort as number)) + 1;
			const row = sharedId === null ? { kind, data: {} } : { kind, shared: sharedId };
			const refused = await options.save(ctx, [...current, { id: blockId(), active: false, sort, ...row }]);
			return refused ?? Effects.make().notify("Block added (inactive) and saved. Configure and activate it when ready.").redirect(options.back);
		}, ["form"]);
}

/**
 * PageEditBlockTabs::shareAction: the block's content as it is in the form,
 * unsaved edits included, becomes a new shared block and the row a
 * reference to it. A refused page save removes the new shared block again.
 */
function shareAction(s: S, cms: Cms, rows: Dict[], id: string, options: BlockTabsOptions) {
	const blocks = cms.config.blocks;
	return s
		.action(`blockShare_${id}`)
		.label("Make shared")
		.outlined()
		.confirm("Make this block shared?", "Its current content becomes a shared block named after this page; this tab turns into a reference you can place on other pages. Rename it on the shared block page.")
		.withoutValidation(options.skipsValidation)
		.handle(async (ctx) => {
			const current = rowsFromForm(blocks, rows, ctx.form as Dict);
			const row = current.find((entry) => entry.id === id && entry.shared === undefined);
			if (row === undefined || options.sharing === undefined) {
				return Effects.make().notify("This block is no longer on the page. Reload to see the latest version.", "error");
			}
			const store = siteOf(cms, ctx).sharedBlocks;
			const kind = row.kind as string;
			const name = await freeSharedName(store, `${blocks.get(kind).label} — ${options.sharing.owner}`);
			const sharedId = await store.create({ name, kind, data: isDict(row.data) ? row.data : {} });
			const next = current.map((entry) => (entry.id === id ? { id, kind, active: entry.active, sort: entry.sort, shared: sharedId } : entry));
			let refused: Effects | void;
			try {
				refused = await options.save(ctx, next);
			} catch (error) {
				await store.delete(sharedId);
				throw error;
			}
			if (refused !== undefined) {
				await store.delete(sharedId);
				return refused;
			}
			return Effects.make().notify(`Shared block «${name}» created — this block now references it`).redirect(options.back);
		}, ["form"]);
}

/** SharedBlocks name for a share: `base`, then "base 2", "base 3"… while taken. */
async function freeSharedName(store: Site["sharedBlocks"], base: string): Promise<string> {
	const taken = new Set((await store.all()).map((block) => block.name));
	let name = base;
	for (let i = 2; taken.has(name); i++) {
		name = `${base} ${i}`;
	}
	return name;
}
