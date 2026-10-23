import { type ActionCtx, Effects, formatPhpDate, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import type { Page } from "../content/page";
import { PageRuleError } from "../content/pageRules";
import type { PageRevision } from "../content/revisions";
import type { Site } from "../site";
import { siteOf } from "./support";

const KIND_LABELS: Record<string, string> = { create: "Created", save: "Saved", restore: "Restored" };

const actorKey = (ctx: ActionCtx): string | number | null => {
	const user = ctx.user as { id?: unknown } | null;
	return typeof user?.id === "number" || typeof user?.id === "string" ? user.id : null;
};

/**
 * PageEditRevisionsAction: a "Revisions" slide-over listing the page's
 * snapshots newest first, with a restore on every row but the newest.
 * Null when revisions are off.
 */
export async function revisionsAction(s: S, cms: Cms, site: Site, page: Page, back: string): Promise<unknown | null> {
	if (!site.pages.revisions.enabled) {
		return null;
	}
	const revisions = await site.pages.revisions.list(page.id);
	const body =
		revisions.length === 0
			? s.stack([s.displayText("No revisions yet.").variant("muted")])
			: s.stack(revisions.map((revision, index) => row(s, cms, page, revision, index === 0, back)));
	const action = s.action("pageRevisions").label("Revisions").icon("history").modal("Revisions", body).slideOver();
	return revisions.length > 0 ? action.badge(revisions.length) : action;
}

function row(s: S, cms: Cms, page: Page, revision: PageRevision, isNewest: boolean, back: string) {
	const meta = s.flex(
		[
			s.displayText(revision.createdAt === null ? "" : String(formatPhpDate(revision.createdAt, "d.m.Y H:i"))),
			s.displayText(revision.userId === null ? "Unknown author" : `User #${String(revision.userId)}`).variant("muted"),
			s.displayText(KIND_LABELS[revision.kind] ?? revision.kind).variant("muted"),
		],
		{ gap: 4, wrap: true },
	);
	const end = isNewest
		? s.displayValue("Current").badge({ Current: { color: "success" } })
		: s
				.action(`pageRevisionRestore_${revision.id}`)
				.label("Restore")
				.confirm("Restore this revision?", "The page content goes back to this snapshot. Status, place in the tree and slugs stay.")
				.handle(async (ctx) => {
					try {
						await siteOf(cms, ctx).pages.restore(page.id, revision.id, { by: actorKey(ctx) });
					} catch (error) {
						if (error instanceof PageRuleError) {
							return Effects.make().notify(error.message, "error");
						}
						throw error;
					}
					return Effects.make().notify("Revision restored").redirect(back);
				});
	return s.flex([s.stack([meta]), s.spacer(), end], { align: "center", gap: 3, variant: "card" });
}
