/** Where cached content lives across requests: a Map on a VPS, KV on Workers, Redis behind an adapter. */
export type CmsCacheStore = {
	get(key: string): Promise<string | null | undefined> | string | null | undefined;
	set(key: string, value: string, ttlSeconds: number): Promise<void> | void;
};

/** An in-process store with expiry: one Node/Bun server, or tests. */
export function memoryCache(): CmsCacheStore {
	const entries = new Map<string, { value: string; expires: number }>();
	return {
		get: (key) => {
			const entry = entries.get(key);
			if (entry === undefined || entry.expires <= Date.now()) {
				entries.delete(key);
				return null;
			}
			return entry.value;
		},
		set: (key, value, ttlSeconds) => {
			entries.set(key, { value, expires: Date.now() + ttlSeconds * 1000 });
		},
	};
}

/** The slice of a Workers KV namespace the cache uses. */
export type KvNamespaceLike = {
	get(key: string): Promise<string | null>;
	put(key: string, value: string, options?: { expirationTtl?: number }): Promise<void>;
};

/**
 * A Workers KV namespace as the store. KV is eventually consistent (about a
 * minute across locations) and takes TTLs of at least 60 seconds, so a
 * content change may show that much later in other regions.
 */
export function kvCache(kv: KvNamespaceLike): CmsCacheStore {
	return {
		get: (key) => kv.get(key),
		set: (key, value, ttlSeconds) => kv.put(key, value, { expirationTtl: Math.max(60, ttlSeconds) }),
	};
}

const VERSION_KEY = "tbtop-cms:content-version";
const VERSION_TTL = 315_360_000;

/**
 * CmsCache: frontend reads under a content version; any content write bumps
 * the version, so every older entry stops being read and ages out.
 */
export class ContentCache {
	private version: string | null = null;

	constructor(
		private readonly store: CmsCacheStore,
		private readonly ttl: number,
	) {}

	async remember<T>(key: string, compute: () => Promise<T>): Promise<T> {
		const versioned = `tbtop-cms:v${await this.currentVersion()}:${key}`;
		const hit = await this.store.get(versioned);
		if (typeof hit === "string") {
			return JSON.parse(hit) as T;
		}
		const value = await compute();
		await this.store.set(versioned, JSON.stringify(value), this.ttl);
		return value;
	}

	async bump(): Promise<void> {
		this.version = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
		await this.store.set(VERSION_KEY, this.version, VERSION_TTL);
	}

	private async currentVersion(): Promise<string> {
		if (this.version === null) {
			const stored = await this.store.get(VERSION_KEY);
			this.version = typeof stored === "string" && stored !== "" ? stored : "0";
		}
		return this.version;
	}
}
