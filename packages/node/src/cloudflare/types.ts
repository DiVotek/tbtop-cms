import type { R2Like } from "@tbtop/admin";
import type { KvNamespaceLike } from "../support/cache";

// The slice of the Workers runtime types the package uses, so it needs no
// @cloudflare/workers-types; a host's real types fit these shapes.

export type D1Result<T = Record<string, unknown>> = { results: T[]; success: boolean; meta: { changes: number; last_row_id: number; duration: number } };
export type D1PreparedStatement = {
	bind(...values: unknown[]): D1PreparedStatement;
	all<T = Record<string, unknown>>(): Promise<D1Result<T>>;
	run<T = Record<string, unknown>>(): Promise<D1Result<T>>;
	first<T = unknown>(column?: string): Promise<T | null>;
	raw<T = unknown[]>(): Promise<T[]>;
};
export type D1Database = {
	prepare(sql: string): D1PreparedStatement;
	batch<T = Record<string, unknown>>(statements: D1PreparedStatement[]): Promise<D1Result<T>[]>;
	exec(sql: string): Promise<unknown>;
};
export type ExecutionContext = { waitUntil(promise: Promise<unknown>): void; passThroughOnException(): void };
export type ScheduledController = { scheduledTime: number; cron: string };

/** The bindings `cloudflare(site)` reads, by name (wrangler.jsonc declares them). */
export type CloudflareEnv = {
	/** D1: the CMS's tables and Better Auth's. */
	DB: D1Database;
	/** KV: the settings and languages cache; off without it. */
	CACHE?: KvNamespaceLike;
	/** R2: uploads, served at `MEDIA_URL` (default `/media`); memory without it, with a warning. */
	MEDIA?: R2Like;
	MEDIA_URL?: string;
	/** Secret: signs sessions; required while Better Auth is on. */
	BETTER_AUTH_SECRET?: string;
	BETTER_AUTH_URL?: string;
	/** Secrets: the first admin, created on boot when missing. */
	TBTOP_ADMIN_EMAIL?: string;
	TBTOP_ADMIN_PASSWORD?: string;
};
