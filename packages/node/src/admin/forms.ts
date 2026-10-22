import { type ActionCtx, Column, definePage, Effects, formatPhpDate, HttpError, type S, Tab } from "@tbtop/admin";
import type { Cms } from "../cms";
import { translationText } from "../content/page";
import { leadIdentity } from "../forms/lead";
import { retrySubmission } from "../forms/notifier";
import { isRetryable, LEAD_STATUSES, type LeadStatus, type Submission, submissionFromRow } from "../forms/submissions";
import type { Site } from "../site";
import { adminPrefix, NAV, siteOf } from "./support";

const LEAD_LABELS: Record<LeadStatus, string> = { new: "New", read: "Read", processed: "Processed", discarded: "Discarded" };

const LEAD_BADGES = {
	new: { label: "New", color: "danger" },
	read: { label: "Read", color: "info" },
	processed: { label: "Processed", color: "success" },
	discarded: { label: "Discarded", color: "gray" },
} as const;

const DELIVERY_BADGES = {
	pending: { label: "Pending", color: "warning" },
	processing: { label: "Processing", color: "warning" },
	handled: { label: "Delivered", color: "success" },
	failed: { label: "Failed", color: "danger" },
} as const;

const DATE_FORMAT = "M j, Y H:i";

const leadLabel = (payload: Record<string, unknown>): string => leadIdentity(payload) ?? "—";

/** The page's name in the admin's default locale, else "Page #id"; a deleted page says so. */
async function pageLabels(site: Site, ids: number[]): Promise<Map<number, string>> {
	const fallback = await site.languages.defaultLocale();
	const labels = new Map<number, string>();
	for (const id of new Set(ids)) {
		const page = await site.pages.find(id);
		const name = page === null ? null : translationText(page, "name", fallback, fallback);
		labels.set(id, page === null ? "Deleted page" : name !== null && name.trim() !== "" ? name : `Page #${id}`);
	}
	return labels;
}

const selected = (ctx: ActionCtx): number[] => ctx.selection.map(Number).filter(Number.isInteger);

/** FormSubmissionsPage: the inbox. Row click opens the detail page; retry stays here for a failed row. */
export function submissionsIndex(cms: Cms) {
	const prefix = adminPrefix(cms);
	return definePage({
		path: "form-submissions",
		title: "Inbox",
		nav: { group: NAV.content, label: "Inbox", icon: "inbox", order: 50 },
		view: async (s, { request }) => {
			const site = cms.site(request);
			const pageIds = await site.submissions.query().select("page_id").distinct().execute();
			const pages = await pageLabels(
				site,
				pageIds.map((row) => Number(row.page_id)).filter((id) => id > 0),
			);
			const blockLabel = (kind: string) => (cms.config.blocks.has(kind) ? cms.config.blocks.get(kind).label : kind);
			return s.stack([
				s
					.table("formSubmissions")
					.columns([
						Column.make("payload")
							.label("Lead")
							.emphasized()
							.formatUsing((_, row) => leadLabel(submissionFromRow(row as never).payload)),
						Column.make("kind")
							.label("Form")
							.formatUsing((value) => blockLabel(String(value))),
						Column.make("page_id")
							.label("Page")
							.formatUsing((value) => (value === null ? "Deleted page" : (pages.get(Number(value)) ?? `Page #${String(value)}`))),
						Column.make("lead_status").label("Status").badge(LEAD_BADGES),
						Column.make("created_at").label("Submitted").datetime(DATE_FORMAT).muted().sortable(),
						Column.make("status").label("Delivery").badge(DELIVERY_BADGES).toggleable(true, true),
						// Never shown: projected so retry's hiddenIf can read it; staleness needs the clock.
						Column.make("retryable")
							.label("Retry")
							.formatUsing((_, row) => (isRetryable(submissionFromRow(row as never)) ? "yes" : "no"))
							.toggleable(false, true),
					])
					.tabs([
						Tab.make("all").label("All"),
						...LEAD_STATUSES.map((status) =>
							Tab.make(status)
								.label(LEAD_LABELS[status])
								.query((qb) => (qb as ReturnType<typeof site.submissions.query>).where("lead_status", "=", status)),
						),
					])
					.filters([
						s.select("status").label("Delivery").options(Object.entries(DELIVERY_BADGES).map(([value, badge]) => ({ value, label: badge.label }))),
						s.select("locale").label("Locale").options((await site.languages.adminActive()).map((row) => ({ value: row.locale, label: row.name }))),
					])
					.defaultSort("created_at", "desc")
					.paginate(25, [25, 50, 100])
					.emptyState("No submissions yet", "Form submissions from the site land here.", "inbox")
					.recordUrl((row) => `${prefix}/form-submissions/${String(row.id)}`)
					.rowActions([
						s
							.action("retry")
							.label("Retry delivery")
							.hiddenIf("retryable", "!=", "yes")
							.handle(async (ctx) => retryEffects(siteOf(cms, ctx), Number(ctx.key())).then((effects) => effects.refreshTable("formSubmissions")), ["row"]),
						s
							.action("deleteSubmission")
							.label("Delete")
							.color("danger")
							.confirm("Delete this submission?", "The lead's details are removed for good.")
							.handle(async (ctx) => {
								await siteOf(cms, ctx).submissions.delete([Number(ctx.key())]);
								return Effects.make().notify("Submission deleted").refreshTable("formSubmissions");
							}, ["row"]),
					])
					.bulkActions([
						leadStatusBulk(s, cms, "processed", "Mark processed"),
						leadStatusBulk(s, cms, "discarded", "Discard"),
						s
							.action("deleteSelected")
							.label("Delete")
							.color("danger")
							.confirm("Delete the selected submissions?", "The leads' details are removed for good.")
							.handle(async (ctx) => {
								await siteOf(cms, ctx).submissions.delete(selected(ctx));
								return Effects.make().notify("Submissions deleted").refreshTable("formSubmissions");
							}, ["selection"]),
					])
					.query(() => site.submissions.query()),
			]);
		},
	});
}

function leadStatusBulk(s: S, cms: Cms, status: LeadStatus, label: string) {
	return s
		.action(`${status}Selected`)
		.label(label)
		.confirm(`${label}: the selected submissions?`, `They move to ${LEAD_LABELS[status]}.`)
		.handle(async (ctx) => {
			await siteOf(cms, ctx).submissions.setLeadStatus(selected(ctx), status);
			return Effects.make().notify(`Moved to ${LEAD_LABELS[status]}`).refreshTable("formSubmissions");
		}, ["selection"]);
}

/** SubmissionRetrier from the admin: a lost claim (double click) reports itself, nothing else runs. */
async function retryEffects(site: Site, id: number): Promise<Effects> {
	const submission = await site.submissions.find(id);
	if (submission === null || !isRetryable(submission)) {
		return Effects.make().notify("A retry is already running.", "info");
	}
	await retrySubmission(site, submission);
	const after = await site.submissions.findOrFail(id);
	if (after.status === "pending" || after.status === "processing") {
		return Effects.make().notify("A retry is already running.", "info");
	}
	return after.status === "handled" ? Effects.make().notify("Delivered") : Effects.make().notify(after.error ?? "Delivery failed.", "error");
}

const valueText = (value: unknown): string =>
	value === null || value === undefined ? "" : typeof value === "object" ? JSON.stringify(value) : String(value);

/** FormSubmissionPage: payload, delivery, lead-status moves and comments. Opening a New one marks it Read. */
export function submissionView(cms: Cms) {
	const prefix = adminPrefix(cms);
	const list = `${prefix}/form-submissions`;
	return definePage({
		path: "form-submissions/$submission",
		title: "Submission",
		view: async (s, { params, request }) => {
			const site = cms.site(request);
			const id = Number(params.submission);
			let submission = Number.isInteger(id) ? await site.submissions.find(id) : null;
			if (submission === null) {
				throw new HttpError(404, "Submission not found.");
			}
			if (submission.leadStatus === "new") {
				await site.submissions.setLeadStatus([id], "read", "new");
				submission = { ...submission, leadStatus: "read" };
			}
			const self = `${list}/${id}`;
			const page = submission.pageId === null ? null : (await pageLabels(site, [submission.pageId])).get(submission.pageId);
			const row = (label: string, value: unknown) =>
				s.flex([s.displayText(label).variant("muted"), typeof value === "string" ? s.displayValue(value) : value], { gap: 2, align: "center" });
			const entries = Object.entries(submission.payload);
			const title = leadIdentity(submission.payload) ?? submission.kind;
			return s.stack([
				s.section({ title, variant: "card" }, [
					s.stack([
						row("Page", page ?? "Deleted page"),
						row("Locale", submission.locale),
						row("Submitted", submittedAt(submission)),
						row("Delivery", s.displayValue(submission.status).badge(DELIVERY_BADGES)),
						...(submission.status === "failed" ? [row("Error", submission.error ?? "")] : []),
					]),
					...(isRetryable(submission)
						? [
								s
									.action("retry")
									.label("Retry delivery")
									.handle(async (ctx) => (await retryEffects(siteOf(cms, ctx), id)).redirect(self)),
							]
						: []),
				]),
				s.section({ title: "Submitted fields", variant: "card" }, [
					entries.length === 0
						? s.displayText("The submission has no fields.").variant("muted")
						: s.stack(entries.map(([key, value]) => row(key, valueText(value)))),
				]),
				s.section({ title: "Status", variant: "card" }, [
					s.flex(
						LEAD_STATUSES.filter((status) => status !== submission.leadStatus).map((status) => statusAction(s, cms, id, status, status === "new" ? list : self)),
						{ gap: 2, wrap: true },
					),
				]),
				await commentsSection(s, cms, site, id, self),
				s.flex(
					[
						s
							.action("deleteSubmission")
							.label("Delete")
							.color("danger")
							.confirm("Delete this submission?", "The lead's details are removed for good.")
							.handle(async (ctx) => {
								await siteOf(cms, ctx).submissions.delete([id]);
								return Effects.make().notify("Submission deleted").redirect(list);
							}),
						s.spacer(),
						s.action("back").label("Close").url(list),
					],
					{ justify: "end" },
				),
			]);
		},
	});
}

/** Back to New leaves the page: re-rendering it would mark the row Read again. */
function statusAction(s: S, cms: Cms, id: number, status: LeadStatus, target: string) {
	const action = s
		.action(`setStatus_${status}`)
		.label(`Mark ${LEAD_LABELS[status].toLowerCase()}`)
		.handle(async (ctx) => {
			await siteOf(cms, ctx).submissions.setLeadStatus([id], status);
			return Effects.make().notify(`Moved to ${LEAD_LABELS[status]}`).redirect(target);
		});
	return status === "discarded" ? action.color("danger").confirm("Discard this submission?", "It moves to Discarded; you can bring it back.") : action;
}

async function commentsSection(s: S, cms: Cms, site: Site, id: number, self: string) {
	const comments = await site.submissions.comments(id);
	return s.section({ title: "Comments", variant: "card" }, [
		comments.length === 0
			? s.displayText("No comments yet.").variant("muted")
			: s.stack(
					comments.map((comment) =>
						s.stack(
							[
								s.flex([s.displayText(`User #${String(comment.userId)}`).variant("subheading"), s.displayText(formatDate(comment.createdAt)).variant("muted")], {
									gap: 2,
									align: "center",
								}),
								s.displayText(comment.body),
							],
							{ gap: 1 },
						),
					),
				),
		s.displayDivider(),
		s
			.form("addCommentForm", [
				s.textarea("body").label("Comment").placeholder("Visible to the team only").required().maxLength(5000),
				s.flex([s.spacer(), s.action("addComment").label("Add comment").color("primary").submit("addCommentForm")], { justify: "end" }),
			])
			.onSubmit(async (ctx) => {
				const user = ctx.user as { id?: unknown } | null;
				const userId = typeof user?.id === "number" || typeof user?.id === "string" ? user.id : null;
				if (userId === null) {
					return Effects.make().notify("Sign in to comment.", "error");
				}
				const form = ctx.form as Record<string, unknown>;
				await siteOf(cms, ctx).submissions.comment(id, userId, String(form.body ?? ""));
				return Effects.make().notify("Comment added").redirect(self);
			}),
	]);
}

const formatDate = (date: Date | null): string => (date === null ? "" : String(formatPhpDate(date, DATE_FORMAT)));

const submittedAt = (submission: Submission): string => formatDate(submission.createdAt);
