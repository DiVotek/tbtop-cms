import { type ActionCtx, Effects, Field, S } from "@tbtop/admin";
import { bindCmsFields } from "../blocks/cmsFields";
import type { BlockRegistry } from "../blocks/registry";
import type { Placement } from "../blocks/catalog";
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

/**
 * Laravel's TrimStrings + ConvertEmptyStringsToNull over a submitted form, which
 * the PHP admin gets from its global middleware: every string, nested ones
 * included, is trimmed, and a blank one becomes null. `password*` keys stay
 * as typed, as Laravel's `except` list keeps them. A richtext editor state
 * (`{ root }`) is left alone: trimming its text nodes would drop the spaces
 * between differently formatted runs.
 */
export const trimmedForm = <F>(ctx: ActionCtx<F>): ActionCtx<F> => ctx.withForm(trimStrings(ctx.form) as F);

function trimStrings(value: unknown, key = ""): unknown {
	if (typeof value === "string") {
		const trimmed = key.startsWith("password") ? value : value.trim();
		return trimmed === "" ? null : trimmed;
	}
	if (Array.isArray(value)) {
		return value.map((item) => trimStrings(item));
	}
	if (isDict(value) && !isDict(value.root)) {
		return Object.fromEntries(Object.entries(value).map(([k, v]) => [k, trimStrings(v, k)]));
	}
	return value;
}

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

const fieldNames = (blocks: BlockRegistry, kind: string): string[] =>
	blocks
		.get(kind)
		.form(new S())
		.filter((field): field is Field => field instanceof Field)
		.map((field) => field.name);

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
		const data = isDict(row.data) ? row.data : {};
		for (const name of fieldNames(blocks, row.kind as string)) {
			if (name in data) {
				out[`block_${id}_${name}`] = data[name];
			}
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
	placement: Placement;
	/** Persists a structural change (add, remove) outside the editor's Save; an Effects (an error notice) answers instead of `back`. */
	save: (ctx: ActionCtx, rows: Dict[]) => Promise<Effects | void>;
	back: string;
	/**
	 * Structural actions skip the form rules (PageEditBlockTabs::skipsGate): only
	 * on a draft page, which cannot leak. A published page or a layout validates first.
	 */
	skipsValidation: boolean;
	sharedNames?: Map<number, string>;
	/** Rows the site skips, by id (rowProblems): listed under Problem blocks instead of a tab. */
	problems?: Map<string, string>;
	/**
	 * The page editor's shared blocks: the add tab offers each one of a placeable
	 * kind by reference, and every inline tab gets a Share action that names the
	 * new shared block "{label} — {owner}".
	 */
	sharing?: { blocks: SharedBlock[]; owner: string };
};

/**
 * The editor tabs of a block list: one per row (its fields, active, sort,
 * remove) plus an "Add block" tab. `save(rows)` persists a structural change.
 */
export function blockTabs(
	s: S,
	cms: Cms,
	rows: Dict[],
	options: BlockTabsOptions,
): { label: string; badge?: string; body: unknown }[] {
	const blocks = cms.config.blocks;
	const tabs: { label: string; badge?: string; body: unknown }[] = [];
	const problems = options.problems ?? new Map<string, string>();
	for (const row of rows) {
		const id = row.id as string;
		const kind = row.kind as string;
		// A row the site skips has no tab: the Problem blocks tab lists it (PageEditBlockTabs::build).
		if (!blocks.has(kind) || problems.has(id)) {
			continue;
		}
		const label = blocks.get(kind).label;
		const shared = typeof row.shared === "number" || typeof row.shared === "string" ? Number(row.shared) : null;
		const body = s.stack([
			...(shared === null
				? bindCmsFields(prefixedForm(blocks, kind, id, s, `block_${id}_active`), fieldBinding(cms))
				: [
						s
							.displayText(`Shared block “${options.sharedNames?.get(shared) ?? `#${shared}`}”: edit it under Design → Shared blocks.`)
							.variant("muted"),
					]),
			s.row([
				s.boolean(`block_${id}_active`).label("Active"),
				s.number(`block_${id}_sort`).label("Sort").rules("nullable|integer"),
			]),
			s.row([removeAction(s, cms, rows, id, options), ...(shared === null && options.sharing !== undefined ? [shareAction(s, cms, rows, id, options)] : [])]),
		]);
		tabs.push({ label: shared === null ? label : `${label} · shared`, ...(row.active === false ? { badge: "·" } : {}), body });
	}
	if (problems.size > 0) {
		tabs.push({ label: "Problem blocks", badge: String(problems.size), body: problemsBody(s, cms, rows, problems, options) });
	}
	tabs.push({ label: "+ Add block", body: addBlockBody(s, cms, rows, options) });
	return tabs;
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
		.confirm("Remove this block?", "Its content is deleted when you confirm.")
		.withoutValidation(options.skipsValidation)
		.handle(async (submitted) => {
			const ctx = trimmedForm(submitted);
			const kept = rowsFromForm(cms.config.blocks, rows, ctx.form as Dict).filter((row) => row.id !== id);
			return (await options.save(ctx, kept)) ?? options.back;
		}, ["form"]);
}

function addBlockBody(
	s: S,
	cms: Cms,
	rows: Dict[],
	options: BlockTabsOptions,
): unknown {
	const blocks = cms.config.blocks;
	const kinds = blocks.forPlacement(options.placement);
	if (kinds.length === 0) {
		return s.displayText("No block kinds are registered for this place.").variant("muted");
	}
	return s.stack([
		...sharedAddRows(s, cms, rows, options),
		s.select("new_block_kind").label("Block kind").options(kinds.map((block) => ({ value: block.kind, label: block.label }))),
		s.row([
			s
				.action("blockAdd")
				.label("Add block")
				.color("primary")
				.withoutValidation(options.skipsValidation)
				.handle(async (submitted) => {
					const ctx = trimmedForm(submitted);
					const form = ctx.form as Dict;
					const kind = typeof form.new_block_kind === "string" ? form.new_block_kind : "";
					if (!blocks.has(kind) || !blocks.get(kind).placements.includes(options.placement)) {
						return Effects.make().notify("Pick a block kind first.", "error");
					}
					return (await options.save(ctx, appendRow(rowsFromForm(blocks, rows, form), { kind, data: {} }))) ?? options.back;
				}, ["form"]),
		]),
	]);
}

/**
 * PageEditBlockTabs::handleAdd: the new row goes last, saved inactive. The
 * action writes outside Save and a layout has no draft, so an empty block
 * would otherwise go live at once; the editor turns it on once it is filled
 * in. A shared reference is configured already, but the same rule keeps
 * "add" from publishing.
 */
function appendRow(current: Dict[], row: { kind: string; data: Dict } | { kind: string; shared: number }): Dict[] {
	const sort = Math.max(-1, ...current.map((entry) => entry.sort as number)) + 1;
	return [...current, { id: blockId(), active: false, sort, ...row }];
}

/** The add tab's shared blocks of a placeable kind, each with its own add action (addChoiceAction/addSharedAction). */
function sharedAddRows(s: S, cms: Cms, rows: Dict[], options: BlockTabsOptions): unknown[] {
	const blocks = cms.config.blocks;
	const placeable = (options.sharing?.blocks ?? [])
		.filter((shared) => blocks.has(shared.kind) && blocks.get(shared.kind).placements.includes(options.placement))
		.sort((a, b) => a.name.localeCompare(b.name));
	if (placeable.length === 0) {
		return [];
	}
	return [
		s.displayText("Add a shared block: its content is edited in one place and shows wherever it is placed.").variant("muted"),
		...placeable.map((shared) =>
			s.row([
				s.displayText(`${shared.name} · ${blocks.get(shared.kind).label}`),
				s
					.action(`blockAddShared_${shared.id}`)
					.label("Add")
					.withoutValidation(options.skipsValidation)
					.handle(async (submitted) => {
						const ctx = trimmedForm(submitted);
						const current = rowsFromForm(blocks, rows, ctx.form as Dict);
						return (await options.save(ctx, appendRow(current, { kind: shared.kind, shared: shared.id }))) ?? options.back;
					}, ["form"]),
			]),
		),
		s.displayText("Or add a new block of its own:").variant("muted"),
	];
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
		.label("Share")
		.confirm("Share this block?", "Its content moves to a new shared block that other pages can place too.")
		.withoutValidation(options.skipsValidation)
		.handle(async (submitted) => {
			const ctx = trimmedForm(submitted);
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
			return Effects.make().notify(`Shared as “${name}”`).redirect(options.back);
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
