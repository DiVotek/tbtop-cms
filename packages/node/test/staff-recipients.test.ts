import { describe, expect, test } from "bun:test";
import { memoryNotifications } from "@tbtop/admin";
import { staffRecipients } from "../src";
import { makeCms } from "./support";

const transport = { send: async () => {} };

describe("staffRecipients", () => {
	test("the bell's staff in their saved admin locale, the mail recipients and the Telegram chats in the given one", async () => {
		const { cms, db } = await makeCms(null, {
			admin: { locales: ["en", "uk"], locale: "en" },
			notifications: {
				mail: transport,
				telegram: { botToken: "T0KEN" },
				bell: { store: memoryNotifications(), staff: () => [{ id: 1 }, { id: 2 }] },
			},
		});
		const now = "2026-11-02 00:00:00";
		await db.insertInto("tbtop_user_profiles").values({ user_id: 2, is_owner: 0, locale: "uk", created_at: now, updated_at: now }).execute();
		const site = cms.site();
		await site.settings.set("notifications.mail.recipients", "team@example.test; bad-address");
		await site.settings.set("notifications.telegram.chat_ids", "-1001 # Sales");
		expect(await staffRecipients(site, "uk")).toEqual([
			{ audience: "staff", locale: "en", user: { id: 1 } },
			{ audience: "staff", locale: "uk", user: { id: 2 } },
			{ audience: "staff", locale: "uk", email: "team@example.test" },
			{ audience: "staff", locale: "uk", phone: "-1001" },
		]);
		expect(await staffRecipients(site, "en", ["mail"])).toEqual([{ audience: "staff", locale: "en", email: "team@example.test" }]);
	});

	test("a channel the host did not configure adds nobody; the locale defaults to the panel's", async () => {
		const { cms } = await makeCms(null, { admin: { locales: ["uk", "en"], locale: "uk" } });
		const site = cms.site();
		await site.settings.set("notifications.mail.recipients", "team@example.test");
		await site.settings.set("notifications.telegram.chat_ids", "42");
		expect(await staffRecipients(site)).toEqual([]);
		const { cms: withMail } = await makeCms(null, { admin: { locales: ["uk", "en"], locale: "uk" }, notifications: { mail: transport } });
		await withMail.site().settings.set("notifications.mail.recipients", "team@example.test");
		expect(await staffRecipients(withMail.site())).toEqual([{ audience: "staff", locale: "uk", email: "team@example.test" }]);
	});
});
