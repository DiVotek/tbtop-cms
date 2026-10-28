import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { ContentCache } from "../support/cache";
import { SecretBox } from "../support/secretBox";
import { sqlDate } from "../support/values";

/**
 * Dotted-key settings (Settings.php): each value is JSON text. A secret key's
 * JSON is sealed with `settingsKey` (AES-GCM) and never reaches the frontend;
 * without a key a secret reads as the fallback and cannot be written. The
 * frontend gets them as one object (`settingsObject`), never the rows.
 */
export class Settings {
	/** The rows as one promise: concurrent readers of a render share the query. */
	private raw: Promise<Map<string, string | null>> | null = null;
	private readonly box: SecretBox | null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly options: {
			secretKeys: string[];
			secretKey?: string;
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
		await this.changed(key);
	}

	async setMany(values: Record<string, unknown>): Promise<void> {
		for (const [key, value] of Object.entries(values)) {
			await this.set(key, value);
		}
	}

	async forget(key: string): Promise<void> {
		await this.db.deleteFrom("tbtop_settings").where("key", "=", key).execute();
		await this.changed(key);
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

	private rawAll(): Promise<Map<string, string | null>> {
		if (this.raw === null) {
			const cache = this.options.cache;
			const loading = (cache?.read === true ? cache.store.remember("settings", () => this.query()) : this.query()).then((rows) => new Map(rows));
			this.raw = loading;
			loading.catch(() => {
				if (this.raw === loading) {
					this.raw = null;
				}
			});
		}
		return this.raw;
	}

	private async query(): Promise<[string, string | null][]> {
		return (await this.db.selectFrom("tbtop_settings").select(["key", "value"]).execute()).map((row) => [row.key, row.value]);
	}

	/**
	 * A `system.*` key (the scheduler's heartbeat, every minute) is never on
	 * the frontend, so it does not bump the content cache: PHP keeps it in
	 * the cache, out of the content version.
	 */
	private async changed(key: string): Promise<void> {
		this.raw = null;
		if (key.startsWith("system.")) {
			return;
		}
		await this.options.cache?.store.bump();
	}
}
