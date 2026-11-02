import { adminLocale, localesFor } from "../admin/locales";
import type { Site } from "../site";
import type { Recipient } from "./types";

/** A raw setting split on comma, semicolon or newline, from a string or a list (Mail\Recipients, ChatIds). */
const pieces = (raw: unknown): string[] =>
	(Array.isArray(raw) ? raw.map(String) : typeof raw === "string" ? raw.split(/[,;\r\n]+/) : []).map((piece) => piece.trim()).filter((piece) => piece !== "");

export const mailRecipients = (raw: unknown): string[] => pieces(raw).filter((email) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email));

/** Chat ids are signed integers; a `# label` after one is the admin's note and never sent. */
export const chatIds = (raw: unknown): string[] => [
	...new Set(
		pieces(raw)
			.map((piece) => piece.split("#", 1)[0]?.trim() ?? "")
			.filter((id) => /^-?\d+$/.test(id)),
	),
];

/**
 * The staff a notification reaches, as the form-submission fan-out builds
 * them: the bell's staff users in each one's saved admin locale, the mail
 * recipients and the Telegram chats from the notification settings in
 * `locale` (the panel's default UI locale unless given). A channel the host
 * did not configure adds nobody; `channels` keeps only those keys (a type's
 * enabled channels), so the bell's staff are not read for a type without it.
 */
export async function staffRecipients(site: Site, locale: string = adminLocale(site.config), channels?: readonly string[]): Promise<Recipient[]> {
	const wanted = (key: string) => channels === undefined || channels.includes(key);
	const recipients: Recipient[] = [];
	const bell = site.config.notifications?.bell;
	if (wanted("bell") && bell !== undefined) {
		const staff = await bell.staff();
		const locales = await localesFor(site.config, staff);
		recipients.push(...staff.map((user, index): Recipient => ({ audience: "staff", locale: locales[index] ?? locale, user })));
	}
	if (wanted("mail") && site.notifications.hasChannel("mail")) {
		const emails = mailRecipients(await site.settings.get("notifications.mail.recipients"));
		recipients.push(...emails.map((email): Recipient => ({ audience: "staff", locale, email })));
	}
	if (wanted("telegram") && site.notifications.hasChannel("telegram")) {
		const ids = chatIds(await site.settings.get("notifications.telegram.chat_ids"));
		recipients.push(...ids.map((phone): Recipient => ({ audience: "staff", locale, phone })));
	}
	return recipients;
}
