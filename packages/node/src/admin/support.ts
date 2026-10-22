import { type ActionCtx, Effects, Field, S } from "@tbtop/admin";
import type { BlockRegistry } from "../blocks/registry";
import type { Placement } from "../blocks/catalog";
import type { Cms } from "../cms";
import type { Site } from "../site";
import { type Dict, isDict } from "../support/values";

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

/**
 * The editor tabs of a block list: one per row (its fields, active, sort,
 * remove) plus an "Add block" tab. `save(rows)` persists a structural change.
 */
export function blockTabs(
	s: S,
	cms: Cms,
	rows: Dict[],
	options: {
		placement: Placement;
		save: (ctx: ActionCtx, rows: Dict[]) => Promise<void>;
		back: string;
		sharedNames?: Map<number, string>;
	},
): { label: string; badge?: string; body: unknown }[] {
	const blocks = cms.config.blocks;
	const tabs: { label: string; badge?: string; body: unknown }[] = [];
	for (const row of rows) {
		const id = row.id as string;
		const kind = row.kind as string;
		if (!blocks.has(kind)) {
			continue;
		}
		const label = blocks.get(kind).label;
		const shared = typeof row.shared === "number" || typeof row.shared === "string" ? Number(row.shared) : null;
		const body = s.stack([
			...(shared === null
				? prefixedForm(blocks, kind, id, s, `block_${id}_active`)
				: [
						s
							.displayText(`Shared block “${options.sharedNames?.get(shared) ?? `#${shared}`}”: edit it under Design → Shared blocks.`)
							.variant("muted"),
					]),
			s.row([
				s.boolean(`block_${id}_active`).label("Active"),
				s.number(`block_${id}_sort`).label("Sort").rules("nullable|integer"),
			]),
			s.row([
				s
					.action(`blockRemove_${id}`)
					.label("Remove block")
					.color("danger")
					.confirm("Remove this block?", "Its content is deleted when you confirm.")
					.withoutValidation()
					.handle(async (ctx) => {
						await options.save(ctx, rowsFromForm(blocks, rows, ctx.form as Dict).filter((r) => r.id !== id));
						return options.back;
					}, ["form"]),
			]),
		]);
		tabs.push({ label: shared === null ? label : `${label} · shared`, ...(row.active === false ? { badge: "·" } : {}), body });
	}
	tabs.push({ label: "+ Add block", body: addBlockBody(s, cms, rows, options) });
	return tabs;
}

function addBlockBody(
	s: S,
	cms: Cms,
	rows: Dict[],
	options: { placement: Placement; save: (ctx: ActionCtx, rows: Dict[]) => Promise<void>; back: string },
): unknown {
	const blocks = cms.config.blocks;
	const kinds = blocks.forPlacement(options.placement);
	if (kinds.length === 0) {
		return s.displayText("No block kinds are registered for this place.").variant("muted");
	}
	return s.stack([
		s.select("new_block_kind").label("Block kind").options(kinds.map((block) => ({ value: block.kind, label: block.label }))),
		s.row([
			s
				.action("blockAdd")
				.label("Add block")
				.color("primary")
				.withoutValidation()
				.handle(async (ctx) => {
					const form = ctx.form as Dict;
					const kind = typeof form.new_block_kind === "string" ? form.new_block_kind : "";
					if (!blocks.has(kind) || !blocks.get(kind).placements.includes(options.placement)) {
						return Effects.make().notify("Pick a block kind first.", "error");
					}
					const current = rowsFromForm(blocks, rows, form);
					const sort = Math.max(-1, ...current.map((row) => row.sort as number)) + 1;
					// Saved inactive (PageEditBlockTabs::handleAdd): the action writes outside Save and a layout has no draft,
					// so an empty block would go live at once. The editor turns it on once it is filled in.
					await options.save(ctx, [...current, { id: blockId(), kind, active: false, sort, data: {} }]);
					return options.back;
				}, ["form"]),
		]),
	]);
}
