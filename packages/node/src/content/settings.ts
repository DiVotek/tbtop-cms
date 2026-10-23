import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { ContentCache } from "../support/cache";
import { SecretBox } from "../support/secretBox";
import { sqlDate } from "../support/values";

/** Keys whose values are media references, turned into URLs for the frontend. */
const MEDIA_KEYS = ["site.logo", "site.favicon", "seo.default_og_image", "seo.identity.logo"];
const FRONTEND_PREFIXES = ["site.", "seo."];

/**
 * Dotted-key settings (Settings.php): each value is JSON text. A secret key's
 * JSON is sealed with `settingsKey` (AES-GCM) and never reaches the frontend;
 * without a key a secret reads as the fallback and cannot be written.
 */
export class Settings {
	private raw: Map<string, string | null> | null = null;
	private readonly box: SecretBox | null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly options: {
			secretKeys: string[];
			secretKey?: string;
			mediaUrl: (ref: string) => string | null;
			/** Frontend reads go through `store` when `read`; every write bumps it. */
			cache?: { store: ContentCache; read: boolean } | null;
		},
	) {
		this.box = (options.secretKey ?? "") === "" ? null : new SecretBox(options.secretKey as string);
	}

	isSecret(key: string): boolean {
		return this.options.secretKeys.includes(key);
	}

	async get<T = unknown>(key: string, fallback: T | null = null): Promise<T | unknown> {
		const raw = await this.rawAll();
		if (!raw.has(key)) {
			return fallback;
		}
		const stored = raw.get(key);
		if (stored === null || stored === undefined) {
			return null;
		}
		const value = this.isSecret(key) ? await this.unseal(key, stored) : stored;
		if (value === null) {
			return fallback;
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
		const json = JSON.stringify(value ?? null);
		const encoded = this.isSecret(key) ? await this.seal(key, json) : json;
		const now = sqlDate(new Date());
		const existing = await this.db.selectFrom("tbtop_settings").select("id").where("key", "=", key).executeTakeFirst();
		if (existing === undefined) {
			await this.db.insertInto("tbtop_settings").values({ key, value: encoded, created_at: now, updated_at: now }).execute();
		} else {
			await this.db.updateTable("tbtop_settings").set({ value: encoded, updated_at: now }).where("id", "=", existing.id).execute();
		}
		await this.changed();
	}

	async setMany(values: Record<string, unknown>): Promise<void> {
		for (const [key, value] of Object.entries(values)) {
			await this.set(key, value);
		}
	}

	async forget(key: string): Promise<void> {
		await this.db.deleteFrom("tbtop_settings").where("key", "=", key).execute();
		await this.changed();
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

	private async seal(key: string, json: string): Promise<string> {
		if (this.box === null) {
			throw new Error(`Setting '${key}' is secret: set settingsKey in the CMS config to store it.`);
		}
		return this.box.seal(json);
	}

	private async unseal(key: string, stored: string): Promise<string | null> {
		const plain = this.box === null ? null : await this.box.open(stored);
		if (plain === null) {
			console.warn("settings.secret_unreadable", { key });
		}
		return plain;
	}

	private media(value: unknown): string | null {
		return typeof value === "string" || typeof value === "number" ? this.options.mediaUrl(String(value)) : null;
	}

	private async rawAll(): Promise<Map<string, string | null>> {
		if (this.raw === null) {
			const cache = this.options.cache;
			const rows = cache?.read === true ? await cache.store.remember("settings", () => this.query()) : await this.query();
			this.raw = new Map(rows);
		}
		return this.raw;
	}

	private async query(): Promise<[string, string | null][]> {
		return (await this.db.selectFrom("tbtop_settings").select(["key", "value"]).execute()).map((row) => [row.key, row.value]);
	}

	private async changed(): Promise<void> {
		this.raw = null;
		await this.options.cache?.store.bump();
	}
}
