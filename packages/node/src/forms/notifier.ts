import { type Page, translationText } from "../content/page";
import type { Site } from "../site";
import { formatPhpDate } from "@tbtop/admin";
import { adminLocale } from "../admin/locales";
import type { FormSubmissionPayload } from "../notifications/formSubmission";
import { staffRecipients } from "../notifications/staff";
import type { Delivery } from "../notifications/types";
import { hasString } from "../support/strings";
import { formatFields, leadContact, leadIdentity, leadName } from "./lead";
import { locateForm } from "./locate";
import { type Submission, staleBefore } from "./submissions";

const TYPE = "form.submission";

/**
 * SubmissionNotifier: the form.submission fan-out under the row's claim,
 * Handled when every enabled channel delivered, Failed with every distinct
 * error otherwise. Never throws: this runs after the visitor's answer.
 */
export async function notifySubmission(site: Site, submission: Submission): Promise<void> {
	if (!(await site.submissions.claim(submission.id, "pending"))) {
		return;
	}
	let outcome: { status: "handled" | "failed"; error: string | null } = { status: "failed", error: null };
	try {
		const page = submission.pageId === null ? null : await site.pages.find(submission.pageId);
		const errors = page === null ? [await site.t("form-submissions.errors.page_missing", submission.locale)] : await deliverSubmission(site, submission, page);
		outcome = { status: errors.length === 0 ? "handled" : "failed", error: errors.length === 0 ? null : errors.join(" | ") };
	} catch (error) {
		console.error("form.submission.notify_failed", { submission: submission.id, error });
		outcome = { status: "failed", error: error instanceof Error ? error.message : String(error) };
	} finally {
		await site.submissions.release(submission.id, outcome);
	}
}

/**
 * SubmissionRetrier: the block's own handler again while it never
 * succeeded (handled_at null), then the fan-out; a failed row is retryable
 * at once, a pending/processing one once stale.
 */
export async function retrySubmission(site: Site, submission: Submission): Promise<void> {
	const claimed =
		(await site.submissions.claim(submission.id, "failed")) ||
		(await site.submissions.claim(submission.id, "pending", staleBefore())) ||
		(await site.submissions.claim(submission.id, "processing", staleBefore()));
	if (!claimed) {
		return;
	}
	let error: string | null = await site.t("form-submissions.errors.retry_unfinished", submission.locale);
	try {
		error = await rerun(site, submission);
	} catch (thrown) {
		console.error("form.submission.retry_failed", { submission: submission.id, error: thrown });
		error = thrown instanceof Error ? thrown.message : String(thrown);
	} finally {
		await site.submissions.release(submission.id, { status: error === null ? "handled" : "failed", error });
	}
}

async function rerun(site: Site, submission: Submission): Promise<string | null> {
	const page = submission.pageId === null ? null : await site.pages.find(submission.pageId);
	if (page === null) {
		return site.t("form-submissions.errors.page_missing", submission.locale);
	}
	const located = await locateForm(site, page, submission.blockId, submission.locale);
	if (located === null) {
		return site.t("form-submissions.errors.block_missing", submission.locale);
	}
	if (submission.handledAt === null && located.submit.handle !== undefined) {
		try {
			await located.submit.handle(submission.payload, located.data, located.ctx);
		} catch (error) {
			return error instanceof Error ? error.message : String(error);
		}
		await site.submissions.markHandled(submission.id);
	}
	const errors = await deliverSubmission(site, submission, page);
	return errors.length === 0 ? null : errors.join(" | ");
}

/**
 * The recipients, a payload per reader locale, the send; every distinct
 * error, misconfiguration first. Staff read the admin UI locale, not the
 * page's: the bell each admin's saved one, mail and Telegram the default.
 */
export async function deliverSubmission(site: Site, submission: Submission, page: Page): Promise<string[]> {
	const enabled = await site.notifications.enabledChannels(TYPE);
	const locale = adminLocale(site.config);
	const recipients = await staffRecipients(site, locale, enabled);
	const errors: string[] = [];
	if (enabled.includes("mail") && !site.notifications.hasChannel("mail")) {
		errors.push(await site.t("notifications.errors.mail", locale, { error: "no mail transport is configured" }));
	} else if (enabled.includes("mail") && !recipients.some((recipient) => recipient.email !== undefined)) {
		errors.push(await site.t("notifications.errors.mail_no_recipients", locale));
	}
	if (enabled.includes("telegram") && !recipients.some((recipient) => recipient.phone !== undefined)) {
		errors.push(await site.t("notifications.errors.telegram_not_configured", locale));
	}
	for (const reader of new Set(recipients.map((recipient) => recipient.locale))) {
		const group = recipients.filter((recipient) => recipient.locale === reader);
		const deliveries = await site.notifications.send(TYPE, await submissionPayload(site, submission, page, reader), group);
		for (const delivery of deliveries.filter((one) => one.status === "failed")) {
			errors.push(await errorFor(site, delivery, locale));
		}
	}
	return [...new Set(errors)];
}

/** FormSubmissionPayloadFactory: plain values, captured once, rendered per recipient. */
export async function submissionPayload(site: Site, submission: Submission, page: Page, locale = adminLocale(site.config)): Promise<FormSubmissionPayload> {
	const name = translationText(page, "name", submission.locale, await site.languages.defaultLocale());
	const prefix = `/${(site.config.adminPrefix ?? "admin").replace(/^\/+|\/+$/g, "")}`;
	return {
		leadIdentity: leadIdentity(submission.payload) ?? (await site.t("form-submissions.values.lead_unknown", locale)),
		pageName: typeof name === "string" && name.trim() !== "" ? name : await site.t("form-submissions.values.page_number", locale, { id: page.id }),
		locale: submission.locale,
		submittedAt: String(formatPhpDate(submission.createdAt ?? new Date(), await site.t("form-submissions.date_format", locale))),
		fields: formatFields(submission.payload),
		fieldLabels: await fieldLabels(site, submission, page, locale),
		actionUrl: site.absolute(`${prefix}/form-submissions/${submission.id}`),
		actionLabel: await site.t("notifications.bell.view", locale),
		leadName: leadName(submission.payload) ?? "",
		leadContact: leadContact(submission.payload) ?? "",
	};
}

/**
 * The submitting form's `labels()` in the readers' locale, kept to the
 * payload's keys and non-blank text. A form that cannot be found or whose
 * labels throw leaves the raw keys: a label never fails a delivery.
 */
async function fieldLabels(site: Site, submission: Submission, page: Page, locale: string): Promise<Record<string, string>> {
	try {
		const located = await locateForm(site, page, submission.blockId, locale);
		if (located?.submit.labels === undefined) {
			return {};
		}
		const labels = await located.submit.labels(locale, located.data, located.ctx);
		return Object.fromEntries(
			Object.keys(submission.payload)
				.map((key) => [key, labels[key]] as const)
				.filter((entry): entry is readonly [string, string] => typeof entry[1] === "string" && entry[1].trim() !== ""),
		);
	} catch (error) {
		console.warn("form.submission.labels_failed", { submission: submission.id, error });
		return {};
	}
}

/** A render failure names the channel; a send failure reads through the channel's own error text. */
async function errorFor(site: Site, delivery: Delivery, locale: string): Promise<string> {
	const channel = hasString(`notifications.channels.${delivery.channel}`, site.config.strings)
		? await site.t(`notifications.channels.${delivery.channel}`, locale)
		: delivery.channel;
	const error = delivery.error ?? "";
	if (delivery.stage === "render") {
		return site.t("notifications.errors.render", locale, { channel, error });
	}
	return hasString(`notifications.errors.${delivery.channel}`, site.config.strings)
		? site.t(`notifications.errors.${delivery.channel}`, locale, { error })
		: site.t("notifications.errors.channel", locale, { channel, error });
}
