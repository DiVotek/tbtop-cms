import { definePage, Field, S } from "@tbtop/admin";
import type { BlockKind } from "../blocks/registry";
import type { Cms } from "../cms";
import { decodeJson, isDict } from "../support/values";
import { adminPrefix, listHeader, NAV } from "./support";

type FieldInfo = { name: string; label: string; type: string; required: boolean; translatable: boolean };
type Usage = { name: string; url: string };

/**
 * BlocksPage: a read-only showcase of the registered block kinds, a card
 * per kind with a colored placeholder, its category and how many pages
 * place it; Details opens the kind's fields and the pages that use it.
 */
export function blocksShowcase(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "blocks",
		...listHeader(
			"design",
			"Blocks",
			"The content blocks available in this site. Blocks are placed and edited inside page content — this is a read-only overview of what each one offers.",
		),
		nav: { group: NAV.design, label: "Blocks", icon: "blocks", order: 40 },
		view: async (s, { request }) => {
			const blocks = cms.config.blocks.all();
			if (blocks.length === 0) {
				return s.stack([s.displayAlert("Register block kinds in createCms({ blocks }) to see them here.").title("No blocks registered")]);
			}
			const usage = await blockUsage(cms, request, prefix);
			return s.stack([s.grid({ cols: { sm: 2, md: 3, lg: 4 }, gap: 4 }, blocks.map((block) => card(s, cms, block, usage.get(block.kind) ?? [])))]);
		},
	});
}

function card(s: S, cms: Cms, block: BlockKind, usage: Usage[]) {
	const category = block.category ?? "Uncategorized";
	return s.stack(
		[
			s.displayHtml(placeholder(block.kind, block.label)),
			s.stack(
				[
					s.displayText(block.label).variant("subheading"),
					s.displayValue(category).badge({ [category]: { color: "info" } }),
					s.flex([s.displayText(`Used on ${usage.length} page${usage.length === 1 ? "" : "s"}`).variant("muted"), details(s, cms, block, usage)], { justify: "between", align: "center" }),
				],
				{ class: "p-3 gap-2" },
			),
		],
		{ class: "rounded-lg border bg-card overflow-hidden gap-0" },
	);
}

function details(s: S, cms: Cms, block: BlockKind, usage: Usage[]) {
	const fields = describeFields(block);
	const meta: Record<string, string> = { Kind: block.kind, ...(block.category === null ? {} : { Category: block.category }) };
	meta.Placements = block.placements.map((placement) => placementLabel(cms, placement)).join(", ");
	meta.Fields = String(fields.length);
	return s
		.action(`blockDetails${block.kind}`)
		.label("Details")
		.modal(
			block.label,
			s.stack(
				[
					s.displayHtml(placeholder(block.kind, block.label, "21 / 9", "3rem")),
					...(block.description === null ? [] : [s.displayText(block.description).variant("muted")]),
					s.displayKeyValue(meta),
					s.displayText("Fields").variant("subheading"),
					s.displayHtml(fieldsTable(fields)),
					s.displayText("Used on pages").variant("subheading"),
					s.displayHtml(usageTable(usage)),
				],
				{ class: "gap-4" },
			),
		)
		.modalWidth("2xl");
}

/** BlockShowcase::describeField: what the kind's form declares, field by field. */
function describeFields(block: BlockKind): FieldInfo[] {
	return block
		.form(new S())
		.filter((field): field is Field => field instanceof Field)
		.map((field) => ({
			name: field.name,
			label: field.labelText() ?? field.name,
			type: field.kind,
			required: (field.toNode().toJSON() as { options?: { required?: unknown } }).options?.required === true,
			translatable: field.isTranslatableField(),
		}));
}

function placementLabel(cms: Cms, placement: string): string {
	if (placement === "layout") {
		return "Layout";
	}
	if (placement === "pages") {
		return "All pages";
	}
	return cms.pageTypes.has(placement) ? cms.pageTypes.get(placement).label : placement;
}

/** BlockUsage::index: the live pages whose content places each kind, in tree order. Layout slots are not counted. */
async function blockUsage(cms: Cms, request: Request, prefix: string): Promise<Map<string, Usage[]>> {
	const site = cms.site(request);
	const locale = await site.languages.defaultLocale();
	// Only the columns usage needs: the content is scanned in full, as PHP's BlockUsage does.
	const rows = await site.pages.query().select(["tbtop_pages.id", "tbtop_pages.name", "tbtop_pages.content"]).orderBy("sort_order").orderBy("id").execute();
	const index = new Map<string, Usage[]>();
	for (const row of rows) {
		const content = decodeJson(row.content);
		const kinds = new Set((Array.isArray(content) ? content : []).filter(isDict).map((entry) => entry.kind).filter((kind): kind is string => typeof kind === "string" && kind !== ""));
		const names = decodeJson(row.name);
		const name = isDict(names) && typeof names[locale] === "string" && names[locale].trim() !== "" ? names[locale] : `#${row.id}`;
		for (const kind of kinds) {
			index.set(kind, [...(index.get(kind) ?? []), { name, url: `${prefix}/pages/${row.id}/edit` }]);
		}
	}
	return index;
}

const escape = (value: string): string => value.replace(/[&<>"']/g, (char) => `&#${char.charCodeAt(0)};`);

/** A deterministic hue (0–359) from the kind. */
function hue(kind: string): number {
	let h = 0;
	for (const char of kind) {
		h = (h * 31 + char.charCodeAt(0)) % 360;
	}
	return h;
}

const initials = (label: string): string =>
	label
		.trim()
		.split(/[\s\-_]+/)
		.filter((word) => word !== "")
		.slice(0, 2)
		.map((word) => word[0]?.toUpperCase() ?? "")
		.join("") || "?";

/** BlockShowcaseHtml::placeholder: a colored preview with the initials and the kind tag; every block value escaped. */
function placeholder(kind: string, label: string, aspect = "16 / 9", markSize = "2rem"): string {
	const h = hue(kind);
	return `<div style="--pb:hsl(${h} 45% 92%);--pb-dark:hsl(${h} 30% 22%);position:relative;aspect-ratio:${aspect};display:grid;place-items:center;background:var(--pb);color:hsl(${h} 45% 40%);border-radius:inherit;" data-block-preview>
<span style="position:absolute;top:8px;left:8px;font-family:ui-monospace,monospace;font-size:11px;padding:2px 7px;border-radius:5px;background:rgba(255,255,255,.7);color:inherit;">${escape(kind)}</span>
<span style="font-family:ui-monospace,monospace;font-weight:700;font-size:${markSize};letter-spacing:-.02em;opacity:.85;">${escape(initials(label))}</span>
</div>
<style>[data-theme="dark"] [data-block-preview]{background:var(--pb-dark) !important;}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) [data-block-preview]{background:var(--pb-dark) !important;}}</style>`;
}

const CELL = "padding:9px 14px";
const HEAD_ROW = '<tr style="text-align:left;font-size:11px;text-transform:uppercase;letter-spacing:.05em;opacity:.6">';

const table = (head: string[], rows: string[]): string =>
	`<div style="border:1px solid var(--border);border-radius:.5rem;overflow:hidden"><table style="width:100%;border-collapse:collapse;font-size:13.5px"><thead style="background:var(--muted)">${HEAD_ROW}${head
		.map((cell) => `<th style="${CELL};font-weight:600">${escape(cell)}</th>`)
		.join("")}</tr></thead><tbody>${rows.join("")}</tbody></table></div>`;

const flag = (text: string, strong: boolean): string =>
	`<span style="font-size:10.5px;padding:1px 6px;border-radius:4px;${strong ? "background:var(--foreground);color:var(--background);border:1px solid transparent" : "border:1px solid var(--border);opacity:.75"}">${escape(text)}</span>`;

function fieldsTable(fields: FieldInfo[]): string {
	return table(
		["Field", "Type", "Attributes"],
		fields.map((field) => {
			const flags = [...(field.required ? [flag("required", true)] : []), ...(field.translatable ? [flag("translatable", false)] : [])];
			return `<tr style="border-top:1px solid var(--border)"><td style="${CELL};font-weight:600">${escape(field.label)}<div style="font-family:ui-monospace,monospace;font-size:11.5px;font-weight:400;opacity:.6">${escape(field.name)}</div></td><td style="${CELL};font-family:ui-monospace,monospace;font-size:12px;opacity:.75">${escape(field.type)}</td><td style="${CELL}">${flags.length === 0 ? '<span style="opacity:.4">—</span>' : flags.join(" ")}</td></tr>`;
		}),
	);
}

function usageTable(pages: Usage[]): string {
	if (pages.length === 0) {
		return '<p style="margin:0;font-size:13px;opacity:.6">Not used on any page yet.</p>';
	}
	return table(
		["Page"],
		pages.map((page) => `<tr style="border-top:1px solid var(--border)"><td style="${CELL}"><a href="${escape(page.url)}" style="color:inherit;text-decoration:underline;text-underline-offset:2px">${escape(page.name)}</a></td></tr>`),
	);
}
