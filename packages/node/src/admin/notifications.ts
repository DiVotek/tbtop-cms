import { definePage, Effects, type S } from "@tbtop/admin";
import type { Cms } from "../cms";
import type { NotificationType } from "../notifications/types";
import { runsAsPhp, templateError } from "../notifications/templates";
import type { Site } from "../site";
import { sqlDate } from "../support/values";
import { adminPrefix, editFooter, listHeader, NAV, siteOf } from "./support";

type AnyType = NotificationType<Record<string, unknown>>;

/** Stored as one newline-joined string (mailRecipients/chatIds parse it at send time), edited as chips. */
const TAG_SETTINGS = {
	notification_mail_recipients: "notifications.mail.recipients",
	notification_telegram_chat_ids: "notifications.telegram.chat_ids",
} as const;

const TEXT_SETTINGS = { mail_from_address: "mail.from_address", mail_from_name: "mail.from_name" } as const;

const slug = (key: string): string => key.replace(/[^a-z0-9]+/gi, "_");
const channelsField = (type: string): string => `channels__${slug(type)}`;
const subjectField = (type: string, format: string, locale: string): string => `tpl__${slug(type)}__${format}__${locale}__subject`;
const bodyField = (type: string, format: string, locale: string): string => `tpl__${slug(type)}__${format}__${locale}__body`;

const FORMAT_LABELS: Record<string, string> = { html: "Email", short: "Telegram and bell", text: "Plain text" };

/** Every (type, format, locale) a merchant can override: the formats the type has a built-in template for. */
function slots(site: Site, locales: string[]): { type: AnyType; format: string; locale: string }[] {
	return site.notifications
		.types()
		.flatMap((type) => Object.keys(type.templates).flatMap((format) => locales.map((locale) => ({ type, format, locale }))));
}

const tagValues = (raw: unknown): string[] =>
	typeof raw === "string"
		? raw
				.split(/[,;\r\n]+/)
				.map((piece) => piece.trim())
				.filter((piece) => piece !== "")
		: Array.isArray(raw)
			? raw.map(String)
			: [];

/**
 * NotificationSettingsPage: recipients, the sender, Telegram chats, the
 * channels per type, and Liquid template overrides. The mail transport and
 * the bot token are host config, not settings.
 */
export function notificationSettings(cms: Cms) {
	return definePage({
		path: "notification-settings",
		...listHeader("settings", "Notification settings", "Channels and templates per notification type, plus mail transport and Telegram delivery."),
		nav: { group: NAV.settings, label: "Notifications", icon: "bell", order: 40 },
		view: async (s, { request }) => {
			const site = cms.site(request);
			const locales = (await site.languages.adminActive()).map((row) => row.locale);
			const all = slots(site, locales);
			return s
				.form("notification_settings", [
					s.tabs([
						{ label: "Channels", children: channelFields(s, site) },
						...groups(site).map(([group, types]) => ({
							label: group === "forms" ? "Forms" : group.charAt(0).toUpperCase() + group.slice(1),
							children: types.map((type) => typeSection(s, site, type, locales)),
						})),
					]),
					editFooter(s, `${adminPrefix(cms)}/notification-settings`, s.action("save").label("Save").color("primary").keybinding("mod+s").submit()),
				])
				.record(await record(site, all))
				.onSubmit(async (ctx) => {
					const target = siteOf(cms, ctx);
					const form = ctx.form as Record<string, unknown>;
					const stored = await storedTemplates(target);
					for (const { type, format, locale } of all) {
						const error = overrideError(form, type.key, format, locale, stored.get(`${type.key}|${format}|${locale}`));
						if (error !== null) {
							return Effects.make().notify(`${type.label}, ${FORMAT_LABELS[format] ?? format} (${locale}): ${error}`, "error");
						}
					}
					await saveSettings(target, form);
					await saveTemplates(target, form, all);
					return Effects.make().notify("Settings saved");
				});
		},
	});
}

function groups(site: Site): [string, AnyType[]][] {
	const byGroup = new Map<string, AnyType[]>();
	for (const type of site.notifications.types()) {
		byGroup.set(type.group, [...(byGroup.get(type.group) ?? []), type]);
	}
	return [...byGroup.entries()];
}

function channelFields(s: S, site: Site): unknown[] {
	const has = (key: string) => site.notifications.hasChannel(key);
	return [
		s
			.tags("notification_mail_recipients")
			.label("Email recipients")
			.rules("nullable|array")
			.helperText(has("mail") ? "Every address gets each staff email." : "No mail transport is configured for this site."),
		s.text("mail_from_address").label("From address").rules("nullable|email|max:255"),
		s.text("mail_from_name").label("From name").rules("nullable|max:255"),
		s.displayDivider(),
		s
			.tags("notification_telegram_chat_ids")
			.label("Telegram chat IDs")
			.rules("nullable|array")
			.helperText(has("telegram") ? "A chat or group ID; add “# note” after it to label it." : "No Telegram bot token is configured for this site."),
	];
}

function typeSection(s: S, site: Site, type: AnyType, locales: string[]) {
	// NotificationTemplateFields::channelsFor: only channels whose format the type has a template for.
	const options = site.notifications
		.channels()
		.filter((channel) => type.templates[channel.format] !== undefined)
		.map((channel) => ({ value: channel.key, label: channel.label }));
	const editors = Object.keys(type.templates).flatMap((format) =>
		locales.map((locale) =>
			s.collapsible({ label: `${FORMAT_LABELS[format] ?? format} template · ${locale}`, collapsed: true }, [
				s.text(subjectField(type.key, format, locale)).label("Subject").rules("nullable|string|max:255"),
				s
					.textarea(bodyField(type.key, format, locale))
					.label("Body")
					.rules("nullable|string|max:20000")
					.helperText(`Liquid. Leave empty for the built-in template. Variables: ${(type.variables ?? []).join(", ")}.`),
			]),
		),
	);
	return s.section({ title: type.label, variant: "card" }, [
		s.checkboxlist(channelsField(type.key)).label("Channels").options(options).rules("nullable|array"),
		...editors,
	]);
}

type StoredTemplate = { subject: string; body: string };

async function storedTemplates(site: Site): Promise<Map<string, StoredTemplate>> {
	const rows = await site.config.db.selectFrom("tbtop_notification_templates").select(["type", "format", "locale", "subject", "body"]).execute();
	return new Map(rows.map((row) => [`${row.type}|${row.format}|${row.locale}`, row]));
}

const sameTemplate = (subject: string, body: string, row: StoredTemplate | undefined): boolean =>
	row !== undefined && row.subject.trim() === subject.trim() && row.body.trim() === body.trim();

/**
 * Why an editor's text cannot be saved, or null. PHP runs these rows as
 * Blade, which is code: a Blade row the PHP package wrote may stay as it
 * is, but none is written from here.
 */
function overrideError(form: Record<string, unknown>, type: string, format: string, locale: string, row: StoredTemplate | undefined): string | null {
	const body = String(form[bodyField(type, format, locale)] ?? "");
	const subject = String(form[subjectField(type, format, locale)] ?? "");
	if (body.trim() === "" || sameTemplate(subject, body, row)) {
		return null;
	}
	if (runsAsPhp(body) || runsAsPhp(subject)) {
		return "Templates are Liquid: Blade directives and PHP code are not allowed.";
	}
	return templateError(body) ?? templateError(subject);
}

async function record(site: Site, all: { type: AnyType; format: string; locale: string }[]): Promise<Record<string, unknown>> {
	const out: Record<string, unknown> = {};
	for (const [field, key] of Object.entries(TAG_SETTINGS)) {
		out[field] = tagValues(await site.settings.get(key));
	}
	for (const [field, key] of Object.entries(TEXT_SETTINGS)) {
		out[field] = (await site.settings.get(key)) ?? "";
	}
	for (const type of site.notifications.types()) {
		out[channelsField(type.key)] = await site.notifications.enabledChannels(type.key);
	}
	const stored = await storedTemplates(site);
	for (const { type, format, locale } of all) {
		const row = stored.get(`${type.key}|${format}|${locale}`);
		out[subjectField(type.key, format, locale)] = row?.subject ?? "";
		out[bodyField(type.key, format, locale)] = row?.body ?? "";
	}
	return out;
}

async function saveSettings(site: Site, form: Record<string, unknown>): Promise<void> {
	const put = async (key: string, value: unknown) => (value === null ? site.settings.forget(key) : site.settings.set(key, value));
	for (const [field, key] of Object.entries(TAG_SETTINGS)) {
		const joined = tagValues(form[field]).join("\n");
		await put(key, joined === "" ? null : joined);
	}
	for (const [field, key] of Object.entries(TEXT_SETTINGS)) {
		const value = typeof form[field] === "string" ? (form[field] as string).trim() : "";
		await put(key, value === "" ? null : value);
	}
	for (const type of site.notifications.types()) {
		const value = form[channelsField(type.key)];
		await site.settings.set(`notifications.${type.key}.channels`, Array.isArray(value) ? value.map(String) : []);
	}
}

/** A non-empty body upserts its row; an emptied one deletes it, so the built-in answers again. */
async function saveTemplates(site: Site, form: Record<string, unknown>, all: { type: AnyType; format: string; locale: string }[]): Promise<void> {
	const db = site.config.db;
	for (const { type, format, locale } of all) {
		const body = String(form[bodyField(type.key, format, locale)] ?? "");
		const subject = String(form[subjectField(type.key, format, locale)] ?? "").trim();
		const where = db
			.selectFrom("tbtop_notification_templates")
			.select(["id", "subject", "body"])
			.where("type", "=", type.key)
			.where("format", "=", format)
			.where("locale", "=", locale);
		const existing = await where.executeTakeFirst();
		const now = sqlDate(new Date());
		if (body.trim() !== "" && sameTemplate(subject, body, existing)) {
			continue;
		}
		if (body.trim() === "") {
			if (existing !== undefined) {
				await db.deleteFrom("tbtop_notification_templates").where("id", "=", existing.id).execute();
			}
			continue;
		}
		if (existing === undefined) {
			await db.insertInto("tbtop_notification_templates").values({ type: type.key, format, locale, subject, body, created_at: now, updated_at: now }).execute();
		} else {
			await db.updateTable("tbtop_notification_templates").set({ subject, body, updated_at: now }).where("id", "=", existing.id).execute();
		}
	}
}
