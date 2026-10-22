import type { Site } from "../site";
import { translate } from "../support/strings";
import { sqlDate } from "../support/values";
import { bellChannel, mailChannel, telegramChannel } from "./channels";
import { formSubmissionType } from "./formSubmission";
import { overrideFor, renderOverride } from "./templates";
import type { Channel, Delivery, NotificationType, Recipient, Rendered } from "./types";

type AnyType = NotificationType<Record<string, unknown>>;

/**
 * NotificationRegistry + Notifications::send(): the types, the channels the
 * host configured, and one synchronous fan-out that returns exactly the
 * deliveries it made. A failing template or channel fails that pair only;
 * send() itself never throws for one.
 */
export class Notifications {
	private readonly typeMap = new Map<string, AnyType>();
	private readonly channelMap = new Map<string, Channel>();

	constructor(private readonly site: Site) {
		const config = site.config.notifications ?? {};
		for (const type of [formSubmissionType as unknown as AnyType, ...((config.types ?? []) as AnyType[])]) {
			this.typeMap.set(type.key, type);
		}
		const channels: Channel[] = [];
		if (config.mail !== undefined) {
			channels.push(mailChannel(config.mail, () => this.mailFrom()));
		}
		if (config.telegram !== undefined && config.telegram.botToken.trim() !== "") {
			channels.push(telegramChannel(config.telegram));
		}
		if (config.bell !== undefined) {
			channels.push(bellChannel(config.bell.store, (locale) => this.t("notifications.bell.open", locale)));
		}
		for (const channel of [...channels, ...(config.channels ?? [])]) {
			this.channelMap.set(channel.key, channel);
		}
	}

	types(): AnyType[] {
		return [...this.typeMap.values()];
	}

	type(key: string): AnyType {
		const type = this.typeMap.get(key);
		if (type === undefined) {
			throw new Error(`Notification type [${key}] is not registered.`);
		}
		return type;
	}

	/** The channels a merchant can turn on: mail, Telegram and the bell when configured, plus the host's. */
	channels(): Channel[] {
		return [...this.channelMap.values()];
	}

	hasChannel(key: string): boolean {
		return this.channelMap.has(key);
	}

	/** `notifications.{type}.channels` in Settings, else the type's defaults. */
	async enabledChannels(typeKey: string): Promise<string[]> {
		const configured = await this.site.settings.get(`notifications.${typeKey}.channels`);
		return Array.isArray(configured) ? configured.filter((key): key is string => typeof key === "string") : this.type(typeKey).defaultChannels;
	}

	async send(typeKey: string, payload: Record<string, unknown>, recipients: Recipient[]): Promise<Delivery[]> {
		const type = this.type(typeKey);
		const enabled = await this.enabledChannels(typeKey);
		const deliveries: Delivery[] = [];
		for (const recipient of recipients.filter((one) => type.audiences.includes(one.audience))) {
			for (const channel of enabled.map((key) => this.channelMap.get(key)).filter((one): one is Channel => one?.supports(recipient) === true)) {
				const delivery = await this.deliver(type, channel, payload, recipient);
				if (delivery !== null) {
					deliveries.push(delivery);
				}
			}
		}
		await this.log(deliveries, recipients);
		return deliveries;
	}

	/** One pair's outcome; null when the type has no template for the channel's format. */
	private async deliver(type: AnyType, channel: Channel, payload: Record<string, unknown>, recipient: Recipient): Promise<Delivery | null> {
		const base = { type: type.key, channel: channel.key, recipient: address(recipient) };
		let rendered: Rendered | null;
		try {
			rendered = await this.render(type, channel, payload, recipient);
		} catch (error) {
			console.error("notifications.template_render_failed", { type: type.key, channel: channel.key, locale: recipient.locale, error: message(error) });
			return { ...base, status: "failed", stage: "render", error: message(error) };
		}
		if (rendered === null) {
			return null;
		}
		try {
			await channel.send(rendered, recipient, type.key);
			return { ...base, status: "sent", stage: "send", error: null };
		} catch (error) {
			return { ...base, status: "failed", stage: "send", error: message(error) };
		}
	}

	/** The merchant's Liquid override, else the built-in template; an override that throws falls back to the built-in. */
	private async render(type: AnyType, channel: Channel, payload: Record<string, unknown>, recipient: Recipient): Promise<Rendered | null> {
		const override = await overrideFor(this.site.config.db, type.key, channel.format, recipient.locale);
		if (override !== null) {
			try {
				return await renderOverride(override, payload);
			} catch (error) {
				console.warn("notifications.template_override_failed", { type: type.key, format: channel.format, locale: recipient.locale, error: message(error) });
			}
		}
		const template = type.templates[channel.format];
		if (template === undefined) {
			return null;
		}
		const fallback = await this.site.languages.defaultLocale();
		const t = (key: string, params: Record<string, string | number> = {}) => this.translate(key, recipient.locale, fallback, params);
		return { ...template(payload, { locale: recipient.locale, t }), data: payload };
	}

	private async log(deliveries: Delivery[], recipients: Recipient[]): Promise<void> {
		if (deliveries.length === 0) {
			return;
		}
		const now = sqlDate(new Date());
		const byAddress = new Map(recipients.map((recipient) => [address(recipient), recipient]));
		await this.site.config.db
			.insertInto("tbtop_notification_log")
			.values(
				deliveries.map((delivery) => ({
					type: delivery.type,
					channel: delivery.channel,
					audience: byAddress.get(delivery.recipient)?.audience ?? "staff",
					recipient: delivery.recipient,
					locale: byAddress.get(delivery.recipient)?.locale ?? "",
					status: delivery.status,
					error: delivery.error,
					sent_at: delivery.status === "sent" ? now : null,
					created_at: now,
					updated_at: now,
				})),
			)
			.execute();
	}

	private async mailFrom(): Promise<string | null> {
		const address = await this.site.settings.get("mail.from_address");
		if (typeof address !== "string" || address.trim() === "") {
			return null;
		}
		const name = await this.site.settings.get("mail.from_name");
		return typeof name === "string" && name.trim() !== "" ? `${name.trim()} <${address.trim()}>` : address.trim();
	}

	private translate(key: string, locale: string, fallback: string, params: Record<string, string | number>): string {
		return translate(key, locale, fallback, this.site.config.strings, params);
	}

	private t(key: string, locale: string): string {
		return translate(key, locale, locale, this.site.config.strings);
	}
}

/** What the log records as the recipient: the email, the chat id or the user id. */
function address(recipient: Recipient): string {
	if (recipient.email !== undefined) {
		return recipient.email;
	}
	if (recipient.phone !== undefined) {
		return recipient.phone;
	}
	const id = (recipient.user as { id?: unknown } | null | undefined)?.id;
	return id === undefined ? "" : `user:${String(id)}`;
}

const message = (error: unknown): string => (error instanceof Error ? error.message : String(error));

export type { Channel, Delivery, Format, MailMessage, MailTransport, NotificationType, Recipient, Rendered, Template } from "./types";
