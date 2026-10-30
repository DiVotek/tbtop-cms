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
const TRANSITION_KEY = "next-schedule-transition";

/**
 * CmsCache: frontend reads under a content version; any content write bumps
 * the version, so every older entry stops being read and ages out. The store
 * is written only on a miss and on a bump: a hit costs one read.
 */
export class ContentCache {
	private version: string | null = null;
	/** NextScheduleTransition: the epoch second of the next published_at/unpublish_at, read once per request. */
	private transition: Promise<number | null> | null = null;

	constructor(
		private readonly store: CmsCacheStore,
		private readonly ttl: number,
	) {}

	async remember<T>(key: string, compute: () => Promise<T>, ttl: number | ((value: T) => number) = this.ttl): Promise<T> {
		const versioned = `tbtop-cms:v${await this.currentVersion()}:${key}`;
		const hit = await this.store.get(versioned);
		if (typeof hit === "string") {
			return JSON.parse(hit) as T;
		}
		const value = await compute();
		await this.store.set(versioned, JSON.stringify(value), Math.max(1, typeof ttl === "function" ? ttl(value) : ttl));
		return value;
	}

	/**
	 * rememberLive: like remember, but the entry never outlives the nearest
	 * schedule transition, since a page's live scope changes then without a
	 * write. `next` is that transition's epoch second (null: none scheduled),
	 * itself kept under its own key until it passes, so no cron is needed.
	 */
	async rememberLive<T>(key: string, compute: () => Promise<T>, next: () => Promise<number | null>): Promise<T> {
		this.transition ??= this.remember(TRANSITION_KEY, next, (at) => (at === null ? this.ttl : at - nowSeconds()));
		const at = await this.transition;
		return this.remember(key, compute, at === null ? this.ttl : Math.min(this.ttl, at - nowSeconds()));
	}

	async bump(): Promise<void> {
		this.version = `${Date.now().toString(36)}${Math.random().toString(36).slice(2, 8)}`;
		this.transition = null;
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

const nowSeconds = (): number => Math.floor(Date.now() / 1000);
