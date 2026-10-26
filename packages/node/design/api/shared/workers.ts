import type { R2Like } from "@tbtop/admin";
import type { KvNamespaceLike } from "../../../src";

/**
 * The slice of the Workers runtime types the entries use, so the sketches
 * typecheck without @cloudflare/workers-types. A real host has those; the
 * shapes here are subsets of them.
 */
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

/** The bindings every variant's Worker reads (wrangler.jsonc names them). */
export type Env = {
	DB: D1Database;
	CACHE: KvNamespaceLike;
	MEDIA: R2Like;
	BETTER_AUTH_SECRET: string;
	BETTER_AUTH_URL?: string;
};
