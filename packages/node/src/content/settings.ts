import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { sqlDate } from "../support/values";

/** Keys whose values are media references, turned into URLs for the frontend. */
const MEDIA_KEYS = ["site.logo", "site.favicon", "seo.default_og_image", "seo.identity.logo"];
const FRONTEND_PREFIXES = ["site.", "seo."];

/**
 * Dotted-key settings (Settings.php): each value is JSON text. Secret keys are
 * Laravel-Crypt encrypted in PHP; this port does not decrypt them yet, so they
 * read as the default and never reach the frontend.
 */
export class Settings {
	private raw: Map<string, string | null> | null = null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly options: { secretKeys: string[]; mediaUrl: (ref: string) => string | null },
	) {}

	isSecret(key: string): boolean {
		return this.options.secretKeys.includes(key);
	}

	async get<T = unknown>(key: string, fallback: T | null = null): Promise<T | unknown> {
		const raw = await this.rawAll();
		if (!raw.has(key) || this.isSecret(key)) {
			return fallback;
		}
		const value = raw.get(key);
		if (value === null || value === undefined) {
			return null;
		}
		try {
			return JSON.parse(value);
		} catch {
			console.warn("settings.decode_failed", { key });
			return fallback;
		}
	}

	async string(key: string, fallback = ""): Promise<string> {
		const value = await this.get(key, fallback);
		return typeof value === "string" ? value : typeof value === "number" ? String(value) : fallback;
	}

	async set(key: string, value: unknown): Promise<void> {
		if (this.isSecret(key)) {
			throw new Error(`Setting '${key}' is secret; secret settings are not supported yet.`);
		}
		const now = sqlDate(new Date());
		const encoded = JSON.stringify(value ?? null);
		const existing = await this.db.selectFrom("tbtop_settings").select("id").where("key", "=", key).executeTakeFirst();
		if (existing === undefined) {
			await this.db.insertInto("tbtop_settings").values({ key, value: encoded, created_at: now, updated_at: now }).execute();
		} else {
			await this.db.updateTable("tbtop_settings").set({ value: encoded, updated_at: now }).where("id", "=", existing.id).execute();
		}
		this.raw = null;
	}

	async setMany(values: Record<string, unknown>): Promise<void> {
		for (const [key, value] of Object.entries(values)) {
			await this.set(key, value);
		}
	}

	async forget(key: string): Promise<void> {
		await this.db.deleteFrom("tbtop_settings").where("key", "=", key).execute();
		this.raw = null;
	}

	/** Every `site.*` and `seo.*` key that is not secret, media keys as URLs (`cms.settings`). */
	async forFrontend(): Promise<Record<string, unknown>> {
		const out: Record<string, unknown> = {};
		for (const [key, raw] of await this.rawAll()) {
			if (!FRONTEND_PREFIXES.some((prefix) => key.startsWith(prefix)) || this.isSecret(key)) {
				continue;
			}
			try {
				const value = raw === null ? null : JSON.parse(raw);
				out[key] = MEDIA_KEYS.includes(key) ? this.media(value) : value;
			} catch {
				console.warn("settings.decode_failed", { key });
			}
		}
		return out;
	}

	private media(value: unknown): string | null {
		return typeof value === "string" || typeof value === "number" ? this.options.mediaUrl(String(value)) : null;
	}

	private async rawAll(): Promise<Map<string, string | null>> {
		this.raw ??= new Map(
			(await this.db.selectFrom("tbtop_settings").select(["key", "value"]).execute()).map((row) => [row.key, row.value]),
		);
		return this.raw;
	}
}
