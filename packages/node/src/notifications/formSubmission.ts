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
	actionUrl: string;
	actionLabel: string;
};

const multiline = (value: string) => escapeHtml(value).replace(/\r?\n/g, "<br>\n");

/** The built-in type, with the PHP package's html and short views as functions. */
export const formSubmissionType: NotificationType<FormSubmissionPayload> = {
	key: "form.submission",
	label: "Form submission",
	group: "forms",
	audiences: ["staff"],
	defaultChannels: ["bell"],
	variables: ["leadIdentity", "pageName", "locale", "submittedAt", "fields", "actionUrl", "actionLabel"],
	templates: {
		html: (payload, { t }) => ({
			subject: t("notifications.mail.subject"),
			body: [
				...Object.entries(payload.fields).map(([key, value]) => `<p><strong>${escapeHtml(key)}:</strong> ${multiline(value)}</p>`),
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
				escapeHtml(payload.leadIdentity),
				`${escapeHtml(t("notifications.mail.fields.page"))}: ${escapeHtml(payload.pageName)}`,
				escapeHtml(payload.actionUrl),
			].join("\n"),
		}),
	},
};
