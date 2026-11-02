import { type ActionCtx, Effects, HttpError, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import { draftPage } from "../content/pageTemplate";
import { codeTemplate } from "../content/templates";
import type { Dict } from "../support/values";
import { allows } from "./abilities";
import { blockRecord, blockRows, blockTabs, editFooter, rowProblems, rowsFromForm, siteOf } from "./support";

export type BlockEditorOptions = {
	/** The form's name; unique on the host's page. */
	id: string;
	/** The stored rows; null or `[]` for none yet. */
	content: unknown[] | null;
	/** The page types (or `layout`) whose block kinds the Add tab offers, as a block's `placements` name them. */
	placement: string[];
	/** Persists the rows: on Save, and on a structural change (add, remove) outside it. */
	save: (rows: Dict[], ctx: ActionCtx) => Promise<void>;
	/** The host page's own URL: where a save answers. */
	back: string;
	/** Where Cancel goes; no footer without it (the host places its own Save). */
	cancelUrl?: string;
	/** The notice after Save; "Saved" by default. */
	saved?: string;
	/** True: a structural change saves without the form's rules (a draft that cannot leak). False by default: content that is live validates first. */
	skipsValidation?: boolean;
};

/**
 * The CMS's block editor for a host's own admin page: a tab per block
 * (its fields, active, order, remove), Problem blocks, "+ Add block", and
 * Save. The same editor as the page editor and the layouts, over rows the
 * host keeps where it likes (a stored page template: `site.templates`).
 * Shared blocks are offered by reference to whoever holds the design ability.
 */
export async function blockEditor(cms: Cms, s: S, request: Request, options: BlockEditorOptions): Promise<unknown> {
	const site = cms.site(request);
	const rows = blockRows(options.content);
	const save = async (ctx: ActionCtx, content: Dict[]) => options.save(content, ctx);
	const mayShare = await allows(cms, "tbtop-cms.design");
	const tabs = blockTabs(s, cms, rows, {
		placement: options.placement,
		save,
		back: options.back,
		sharedBlocks: await site.sharedBlocks.all(),
		problems: await rowProblems(site, rows),
		skipsValidation: options.skipsValidation ?? false,
		...(mayShare ? { sharing: { owner: options.id } } : {}),
	});
	const submit = s.action(`${options.id}Save`).label("Save").color("primary").keybinding("mod+s").submit(options.id);
	return s
		.form(options.id, [s.tabs(tabs), ...(options.cancelUrl === undefined ? [s.flex([s.spacer(), submit], { justify: "end" })] : [editFooter(s, options.cancelUrl, submit)])])
		.record(blockRecord(cms.config.blocks, rows))
		.onSubmit(async (ctx) => {
			await options.save(rowsFromForm(cms.config.blocks, rows, ctx.form as Dict), ctx);
			return Effects.make().notify(options.saved ?? "Saved").redirect(options.back);
		});
}

/**
 * `blockEditor` over a page type's stored template (`site.templates`): it
 * starts from the code template when none was saved, and Save stores it,
 * so every page of the type without blocks of its own renders it. Needs
 * the design ability (`tbtop-cms.design`), as the layouts do: 403 without.
 * `blockEditor` checks nothing itself: the host's page gates it.
 */
export async function templateEditor(
	cms: Cms,
	s: S,
	request: Request,
	options: { type: string; back: string; cancelUrl?: string; saved?: string; id?: string },
): Promise<unknown> {
	// A template renders on every page of its type: design work, as the layouts are.
	if (!(await allows(cms, "tbtop-cms.design"))) {
		throw new HttpError(403, "This action is unauthorized.");
	}
	if (!cms.pageTypes.has(options.type)) {
		throw new HttpError(404, "No such page type.");
	}
	const site = cms.site(request);
	// Ids stable across requests (the view and its submit build the form apart), unlike a new page's fresh ones.
	const content = (await site.templates.get(options.type)) ?? codeTemplate(cms.pageTypes, draftPage({ type: options.type, parentId: null, subtype: null, name: {} }));
	return blockEditor(cms, s, request, {
		id: options.id ?? `template_${options.type}`,
		content,
		placement: [options.type],
		save: async (rows, ctx) => {
			if (!(await allows(cms, "tbtop-cms.design"))) {
				throw new HttpError(403, "This action is unauthorized.");
			}
			await siteOf(cms, ctx).templates.save(options.type, rows);
		},
		back: options.back,
		...(options.cancelUrl === undefined ? {} : { cancelUrl: options.cancelUrl }),
		...(options.saved === undefined ? {} : { saved: options.saved }),
	});
}
