import { Notification, NotificationAction, type NotificationStore } from "@tbtop/admin";
import type { Channel, MailTransport } from "./types";

/** Mail through the host's transport; `from` comes from the `mail.from_*` settings. */
export function mailChannel(transport: MailTransport, from: () => Promise<string | null>): Channel {
	return {
		key: "mail",
		label: "Email",
		format: "html",
		supports: (recipient) => typeof recipient.email === "string" && recipient.email !== "",
		send: async (rendered, recipient) => {
			await transport.send({ to: recipient.email ?? "", from: await from(), subject: rendered.subject, html: rendered.body, text: htmlToText(rendered.body) });
		},
	};
}

/**
 * Telegram Bot API over fetch, so it runs on Workers as well as Node. The
 * body is HTML-formatted text (parse_mode HTML), as in TelegramChannel.php.
 */
export function telegramChannel(options: { botToken: string; fetch?: typeof fetch }): Channel {
	const send = options.fetch ?? fetch;
	return {
		key: "telegram",
		label: "Telegram",
		format: "short",
		supports: (recipient) => typeof recipient.phone === "string" && recipient.phone !== "",
		send: async (rendered, recipient) => {
			const response = await send(`https://api.telegram.org/bot${options.botToken}/sendMessage`, {
				method: "POST",
				headers: { "Content-Type": "application/json" },
				body: JSON.stringify({ chat_id: recipient.phone, text: rendered.body.trim(), parse_mode: "HTML", disable_web_page_preview: true }),
			});
			if (!response.ok) {
				const reply = (await response.json().catch(() => null)) as { description?: string } | null;
				throw new Error(reply?.description ?? `HTTP ${response.status}`);
			}
		},
	};
}

/**
 * The admin header bell (BellChannel.php). The short template is Telegram
 * HTML and the bell shows plain text; lines the title or the action link
 * already show are dropped, and the payload's `actionUrl` becomes the link.
 */
export function bellChannel(store: NotificationStore, openLabel: (locale: string) => string): Channel {
	return {
		key: "bell",
		label: "Bell",
		format: "short",
		supports: (recipient) => recipient.user !== undefined && recipient.user !== null,
		send: async (rendered, recipient) => {
			const subject = rendered.subject.trim();
			const actionUrl = typeof rendered.data.actionUrl === "string" ? rendered.data.actionUrl : "";
			const shown = [subject, actionUrl].filter((text) => text !== "");
			const body = htmlToText(rendered.body)
				.split("\n")
				.map((line) => line.trim())
				.filter((line) => line !== "" && !shown.includes(line))
				.join("\n");
			const note = subject === "" ? Notification.make().title(body) : Notification.make().title(subject).body(body);
			if (actionUrl !== "") {
				const label = typeof rendered.data.actionLabel === "string" && rendered.data.actionLabel !== "" ? rendered.data.actionLabel : openLabel(recipient.locale);
				note.actions([NotificationAction.make(label).url(actionUrl)]);
			}
			await note.sendTo(store, [recipient.user]);
		},
	};
}

const ENTITIES: Record<string, string> = { "&amp;": "&", "&lt;": "<", "&gt;": ">", "&quot;": '"', "&#39;": "'", "&nbsp;": " " };

/** The plain-text part of an HTML mail: block ends become newlines, tags go, entities decode. */
export function htmlToText(html: string): string {
	return html
		.replace(/<br\s*\/?>/gi, "\n")
		.replace(/<\/(p|div|li|h[1-6]|tr)>/gi, "\n")
		.replace(/<[^>]+>/g, "")
		.replace(/&(amp|lt|gt|quot|#39|nbsp);/g, (entity) => ENTITIES[entity] ?? entity)
		.replace(/\n{3,}/g, "\n\n")
		.trim();
}
