import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { flag, sqlDate } from "../support/values";
import type { Settings } from "./settings";

/** NotFoundNoise::DEFAULT_IGNORE: scanner probes, not broken links a visitor followed. */
export const DEFAULT_NOT_FOUND_IGNORE = [".php", ".env", "wp-", ".git", ".well-known", "xmlrpc", ".aspx", ".cgi"];
const MAX_PATH = 2048;
const DEFAULT_RETENTION_DAYS = 90;

export type NotFoundHit = { path: string; locale: string; referer: string | null; userAgent: string | null };

/** NotFoundNoise::shouldIgnore: an empty or over-long path, or one containing a pattern (case-insensitive). */
export function isNotFoundNoise(path: string, patterns: readonly string[]): boolean {
	if (path === "" || [...path].length > MAX_PATH) {
		return true;
	}
	const haystack = path.toLowerCase();
	return patterns.some((pattern) => pattern !== "" && haystack.includes(pattern.toLowerCase()));
}

const clip = (value: string | null, limit: number): string | null => (value === null || value === "" ? null : [...value].slice(0, limit).join(""));

async function sha256(text: string): Promise<string> {
	const digest = new Uint8Array(await crypto.subtle.digest("SHA-256", new TextEncoder().encode(text)));
	return [...digest].map((byte) => byte.toString(16).padStart(2, "0")).join("");
}

/**
 * The 404 log (RecordsNotFoundHit, PruneNotFoundHitsCommand): one row per
 * locale and path with a hit counter. Switched by `seo.not_found.enabled`
 * (on unless false), kept for `seo.not_found.retention_days` (default 90).
 */
export class NotFoundLog {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly settings: Settings,
		private readonly ignore: readonly string[] = DEFAULT_NOT_FOUND_IGNORE,
	) {}

	async enabled(): Promise<boolean> {
		const value = await this.settings.get("seo.not_found.enabled", true);
		return value === null || Boolean(value);
	}

	/**
	 * Counts a miss. Update first, insert when no row exists, and update again
	 * when a concurrent insert won the unique key: one statement set for
	 * SQLite, D1, Postgres and MySQL alike.
	 */
	async record(hit: NotFoundHit): Promise<void> {
		if (isNotFoundNoise(hit.path, this.ignore) || !(await this.enabled())) {
			return;
		}
		const hash = await sha256(hit.path);
		const now = sqlDate(new Date());
		const fresh = { last_seen_at: now, last_referer: clip(hit.referer, 2048), last_user_agent: clip(hit.userAgent, 512), updated_at: now };
		if (await this.bump(hit.locale, hash, fresh)) {
			return;
		}
		try {
			await this.db
				.insertInto("tbtop_not_found_hits")
				.values({ locale: hit.locale, path: hit.path, path_hash: hash, hits: 1, first_seen_at: now, is_ignored: flag(false), created_at: now, ...fresh })
				.execute();
		} catch (error) {
			if (!(await this.bump(hit.locale, hash, fresh))) {
				throw error;
			}
		}
	}

	/** Deletes rows not seen within the retention window; how many went. */
	async prune(now = new Date()): Promise<number> {
		const days = Number(await this.settings.get("seo.not_found.retention_days", DEFAULT_RETENTION_DAYS));
		const keep = Number.isFinite(days) && days >= 1 ? Math.floor(days) : DEFAULT_RETENTION_DAYS;
		const cutoff = sqlDate(new Date(now.getTime() - keep * 86_400_000));
		const result = await this.db.deleteFrom("tbtop_not_found_hits").where("last_seen_at", "<", cutoff).executeTakeFirst();
		return Number(result.numDeletedRows);
	}

	private async bump(locale: string, hash: string, fresh: Record<string, string | null>): Promise<boolean> {
		const result = await this.db
			.updateTable("tbtop_not_found_hits")
			.set((eb) => ({ ...fresh, hits: eb("hits", "+", 1) }))
			.where("locale", "=", locale)
			.where("path_hash", "=", hash)
			.executeTakeFirst();
		return Number(result.numUpdatedRows) > 0;
	}
}
