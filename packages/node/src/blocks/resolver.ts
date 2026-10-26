import { S } from "@tbtop/admin";
import type { Page } from "../content/page";
import { refOf } from "../content/sharedBlocks";
import { linkedPageIds } from "../content/link";
import type { LinkTargets, Site } from "../site";
import { type Dict, isDict } from "../support/values";
import { flattenLocales, translatablePaths } from "./locale";

export type ResolvedBlock = { id: string; kind: string; props: Record<string, unknown> };

/** A render spec json-render's Renderer takes: flat elements, `$root` lists the blocks in order. */
export type RenderSpec = {
	root: "$root";
	elements: Record<string, { type: string; props: Record<string, unknown>; children?: string[] }>;
};

export class BlockResolutionError extends Error {}

/** Stored rows ordered by `sort`, the row's index standing in where it has none. */
function sorted(content: unknown[]): unknown[] {
	return content
		.map((raw, index) => ({ raw, sort: isDict(raw) && Number.isInteger(raw.sort) ? (raw.sort as number) : index, index }))
		.sort((a, b) => a.sort - b.sort || a.index - b.index)
		.map((entry) => entry.raw);
}

/**
 * BlockContentResolver::resolveWithFields: the page type's custom fields,
 * then each active row whose kind is registered, its data (or its shared
 * block's) flattened to the locale and resolved to props. A row that fails
 * is skipped with a warning, unless `strictBlocks` is on.
 */
export async function resolveBlocks(
	site: Site,
	content: unknown[],
	locale: string,
	page: Page | null,
): Promise<{ blocks: ResolvedBlock[]; fields: Record<string, unknown> }> {
	const locales = (await site.languages.all()).map((row) => row.locale);
	// Every page link of the pass in two queries, instead of two per link.
	const targets = await site.linkTargets(await linkedIds(site, content, page), locale);
	const fields = page === null ? {} : await resolveFields(site, page, locale, locales, targets);
	const ctx = await site.resolveContext(locale, page, fields, targets);
	const blocks: ResolvedBlock[] = [];
	for (const raw of sorted(content)) {
		const block = await resolveOne(site, raw, ctx, locales);
		if (block !== null) {
			blocks.push(block);
		}
	}
	return { blocks, fields };
}

/** The pages the rows (their shared blocks' data included) and the page's own fields link to. */
async function linkedIds(site: Site, content: unknown[], page: Page | null): Promise<number[]> {
	const ids = linkedPageIds(page?.fields ?? null);
	for (const raw of content) {
		const ref = isDict(raw) ? refOf(raw) : null;
		linkedPageIds(ref === null ? raw : (await site.sharedBlocks.find(ref))?.data, ids);
	}
	return ids;
}

/**
 * Pages' resolved custom fields, as their own page props carry them: what
 * `ctx.pages(value, { fields: true })` adds to each ref. Resolved one level
 * deep: inside, `fields: true` gives empty fields, so two pages that pick
 * each other do not recurse.
 */
export async function resolvePageFields(site: Site, pages: Page[], locale: string): Promise<Record<string, unknown>[]> {
	const locales = (await site.languages.all()).map((row) => row.locale);
	// The pages' links in one lookup for the whole list, not one per page.
	const ids = (await Promise.all(pages.map((page) => linkedIds(site, [], page)))).flat();
	const targets = await site.linkTargets(ids, locale);
	const out: Record<string, unknown>[] = [];
	for (const page of pages) {
		out.push(await resolveFields(site, page, locale, locales, targets, true));
	}
	return out;
}

async function resolveFields(site: Site, page: Page, locale: string, locales: string[], targets: LinkTargets, nested = false): Promise<Record<string, unknown>> {
	const stored = page.fields ?? {};
	const types = site.pageTypes.forPage(page);
	let out: Record<string, unknown> = {};
	for (const [i, type] of types.entries()) {
		try {
			const later = new Set(types.slice(i + 1).flatMap((next) => fieldNames(next.form(new S()))));
			const own = Object.fromEntries(Object.entries(stored).filter(([key]) => !later.has(key)));
			const ctx = await site.resolveContext(locale, page, out, targets, nested);
			const input = flattenLocales(own, locale, ctx.fallbackLocale, locales, translatablePaths(type.form(new S())));
			out = { ...out, ...(await type.resolveFields(input, ctx)) };
		} catch (error) {
			if (site.config.strictBlocks === true) {
				throw error;
			}
			console.warn("page.fields_resolve_failed", { type: type.key, page: page.id, error: String(error) });
		}
	}
	return out;
}

const fieldNames = (fields: unknown[]): string[] =>
	fields.map((field) => (field as { name?: unknown }).name).filter((name): name is string => typeof name === "string");

async function resolveOne(
	site: Site,
	raw: unknown,
	ctx: Awaited<ReturnType<Site["resolveContext"]>>,
	locales: string[],
): Promise<ResolvedBlock | null> {
	if (!isDict(raw) || typeof raw.kind !== "string" || typeof raw.id !== "string" || raw.active === false) {
		return null;
	}
	const { kind, id } = raw as Dict & { kind: string; id: string };
	const problem = await rowProblem(site, raw, kind);
	if (problem !== null) {
		if (site.config.strictBlocks === true) {
			throw new BlockResolutionError(problem);
		}
		return null;
	}
	const block = site.blocks.get(kind);
	const data = (await site.sharedBlocks.dataOf(raw, kind)) ?? {};
	try {
		const flat = flattenLocales(data, ctx.locale, ctx.fallbackLocale, locales, block.translatableFields());
		const props = await block.resolve(flat, ctx);
		return { id, kind, props: isDict(props) ? props : {} };
	} catch (error) {
		if (site.config.strictBlocks === true) {
			throw new BlockResolutionError(`Block [${kind}] failed to resolve: ${String(error)}`);
		}
		console.warn("block.resolve_failed", { kind, id, error: String(error) });
		return null;
	}
}

/** BlockRowProblem::of: an unregistered kind, or a shared reference that is missing or of another kind. */
export async function rowProblem(site: Site, row: Dict, kind: string): Promise<string | null> {
	if (!site.blocks.has(kind)) {
		return `Block kind [${kind}] is not registered.`;
	}
	const ref = refOf(row);
	if (ref === null) {
		return null;
	}
	const shared = await site.sharedBlocks.find(ref);
	if (shared === null) {
		return `Block [${kind}] points at shared block #${ref}, which does not exist.`;
	}
	return shared.kind === kind ? null : `Block [${kind}] points at shared block #${ref} of kind [${shared.kind}].`;
}

/** FormActionBinder: a form block gets the URL it posts to. */
export function bindForms(site: Site, blocks: ResolvedBlock[], page: Page, locale: string): ResolvedBlock[] {
	return blocks.map((block) =>
		site.blocks.has(block.kind) && site.blocks.get(block.kind).submit !== null ? { ...block, props: { ...block.props, ...formActionProps(site, page, block.id, locale) } } : block,
	);
}

/**
 * FormActionProps: the root-relative submit path, and the honeypot input name when one is configured.
 * No origin: `config.url` may differ from the browser's origin (preview port, www), and a cross-origin post fails CORS.
 */
export function formActionProps(site: Site, page: Page | number, blockId: string, locale: string): { action: string; honeypotField?: string } {
	const action = `/_cms/forms/${encodeURIComponent(locale)}/${typeof page === "number" ? page : page.id}/${encodeURIComponent(blockId)}`;
	const honeypot = honeypotField(site);
	return honeypot === null ? { action } : { action, honeypotField: honeypot };
}

export const honeypotField = (site: Site): string | null => {
	const field = site.config.forms?.honeypotField;
	return field === undefined ? "website" : field === null || field.trim() === "" ? null : field;
};

/**
 * The catalog's zod schema is the contract: props that do not match it are
 * dropped, not shipped. That is a resolver/catalog drift, a code bug, so it
 * throws under `strictBlocks` (off by default, as `blocks.strict` in PHP).
 * Props that match ship as resolved, plus the parsed value of each key the
 * resolver left out, so a `.default()` fills it as a page type's schema does
 * for `page.fields`. Keys the resolver set keep their value: the browser
 * parses again, and a `.transform()` must not run twice.
 */
export function validateProps(site: Site, blocks: ResolvedBlock[]): ResolvedBlock[] {
	const strict = site.config.strictBlocks === true;
	return blocks.flatMap((block) => {
		const result = site.blocks.get(block.kind).props.safeParse(block.props);
		if (result.success) {
			// The resolver's keys win: zod strips undeclared ones, and a transform must not run twice.
			return [{ ...block, props: { ...(result.data as ResolvedBlock["props"]), ...block.props } }];
		}
		if (strict) {
			throw new BlockResolutionError(`Block [${block.kind}] props do not match the catalog: ${result.error.message}`);
		}
		console.warn("block.props_invalid", { kind: block.kind, id: block.id, error: result.error.message });
		return [];
	});
}

/** PageSpecAssembler: blocks in order under the root; a duplicate or reserved id is skipped. */
export function assembleSpec(blocks: ResolvedBlock[], rootKind = "Fragment"): RenderSpec {
	const elements: RenderSpec["elements"] = {};
	const children: string[] = [];
	for (const block of blocks) {
		if (block.id === "$root" || block.id in elements) {
			console.warn("block.reserved_or_duplicate_id", { id: block.id, kind: block.kind });
			continue;
		}
		children.push(block.id);
		elements[block.id] = { type: block.kind, props: block.props };
	}
	elements.$root = { type: rootKind, props: {}, children };
	return { root: "$root", elements };
}
