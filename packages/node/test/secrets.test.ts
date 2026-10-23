import { describe, expect, test } from "bun:test";
import { makeCms } from "./support";

const secretConfig = { secretSettings: ["site.api_token"], settingsKey: "test-settings-key" };

describe("secret settings", () => {
	test("a secret is stored sealed, read back, and kept off the frontend", async () => {
		const { cms, db } = await makeCms(null, secretConfig);
		const settings = cms.site().settings;
		await settings.set("site.api_token", "s3cr3t");

		const row = await db.selectFrom("tbtop_settings").select("value").where("key", "=", "site.api_token").executeTakeFirstOrThrow();
		expect(row.value).toStartWith("enc:v1:");
		expect(row.value).not.toContain("s3cr3t");
		expect(await settings.get("site.api_token")).toBe("s3cr3t");
		expect(await settings.forFrontend()).not.toHaveProperty("site.api_token");
	});

	test("without settingsKey a secret cannot be saved", async () => {
		const { cms } = await makeCms(null, { secretSettings: ["site.api_token"] });
		await expect(cms.site().settings.set("site.api_token", "s3cr3t")).rejects.toThrow("settingsKey");
	});

	test("a secret sealed with another key, or stored in plain text, reads as the fallback", async () => {
		const { cms, db } = await makeCms(null, secretConfig);
		await cms.site().settings.set("site.api_token", "s3cr3t");
		await db.insertInto("tbtop_settings").values({ key: "site.plain_token", value: '"plain"', created_at: null, updated_at: null }).execute();

		const { cms: rotated } = await makeCms(null, { secretSettings: ["site.api_token", "site.plain_token"], settingsKey: "another-key", db });
		expect(await rotated.site().settings.get("site.api_token", "none")).toBe("none");
		expect(await rotated.site().settings.get("site.plain_token", "none")).toBe("none");
	});
});
