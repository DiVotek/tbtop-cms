import { escapeHtml } from "./templates";
import type { NotificationType } from "./types";

/** The `form.submission` payload (FormSubmissionPayload.php): plain values, the template variables. */
export type FormSubmissionPayload = {
	leadIdentity: string;
	pageName: string;
	locale: string;
	submittedAt: string;
	/** Field name to display text, in submit order. */
	fields: Record<string, string>;
	/** Field name to the form's label for it in the readers' language; a field without one is left out. */
	fieldLabels: Record<string, string>;
	actionUrl: string;
	actionLabel: string;
	/** The lead's name, '' when the payload has none (leadIdentity still covers it). */
	leadName: string;
	/** How to reach the lead: the email, else a phone; '' when neither. */
	leadContact: string;
};

const multiline = (value: string) => escapeHtml(value).replace(/\r?\n/g, "<br>\n");

/** The built-in type, with the PHP package's html and short views as functions. */
export const formSubmissionType: NotificationType<FormSubmissionPayload> = {
	key: "form.submission",
	label: "Form submission",
	group: "forms",
	audiences: ["staff"],
	defaultChannels: ["bell"],
	variables: ["leadIdentity", "leadName", "leadContact", "pageName", "locale", "submittedAt", "fields", "fieldLabels", "actionUrl", "actionLabel"],
	templates: {
		html: (payload, { t }) => ({
			subject: t("notifications.mail.subject"),
			body: [
				...Object.entries(payload.fields).map(([key, value]) => `<p><strong>${escapeHtml(payload.fieldLabels[key] ?? key)}:</strong> ${multiline(value)}</p>`),
				`<p><strong>${escapeHtml(t("notifications.mail.fields.page"))}:</strong> ${escapeHtml(payload.pageName)}</p>`,
				`<p><strong>${escapeHtml(t("notifications.mail.fields.locale"))}:</strong> ${escapeHtml(payload.locale)}</p>`,
				`<p><strong>${escapeHtml(t("notifications.mail.fields.submitted_at"))}:</strong> ${escapeHtml(payload.submittedAt)}</p>`,
				`<p><a href="${escapeHtml(payload.actionUrl)}">${escapeHtml(t("notifications.mail.view_submission"))}</a></p>`,
			].join("\n"),
		}),
		short: (payload, { t }) => ({
			subject: t("notifications.bell.title"),
			body: [
				`<b>${escapeHtml(t("notifications.telegram.message_title"))}</b>`,
				// Name and contact on their own lines; the one-line identity only when both are missing.
				...(payload.leadName === "" && payload.leadContact === "" ? [payload.leadIdentity] : [payload.leadName, payload.leadContact].filter((line) => line !== "")).map(escapeHtml),
				`${escapeHtml(t("notifications.mail.fields.page"))}: ${escapeHtml(payload.pageName)}`,
				escapeHtml(payload.actionUrl),
			].join("\n"),
		}),
	},
};
