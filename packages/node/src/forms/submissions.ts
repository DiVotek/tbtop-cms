import type { Kysely, Selectable } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { decodeJson, isDict, parseDate, sqlDate } from "../support/values";

/** Delivery status (SubmissionStatus): pending until the fan-out ran, processing while one holds the claim. */
export type SubmissionStatus = "pending" | "processing" | "handled" | "failed";

/** The manager's workflow (LeadStatus), independent of delivery; only `new` counts as unread. */
export type LeadStatus = "new" | "read" | "processed" | "discarded";

export const LEAD_STATUSES: LeadStatus[] = ["new", "read", "processed", "discarded"];

export type Submission = {
	id: number;
	pageId: number | null;
	blockId: string;
	kind: string;
	locale: string;
	/** The submitted fields in submit order. */
	payload: Record<string, unknown>;
	status: SubmissionStatus;
	leadStatus: LeadStatus;
	error: string | null;
	handledAt: Date | null;
	createdAt: Date | null;
	updatedAt: Date | null;
};

/** A Pending or Processing row this old is a dead worker's: retryable (SubmissionRetrier::STALE_AFTER_MINUTES). */
export const STALE_AFTER_MINUTES = 15;

type Row = Selectable<CmsDatabase["tbtop_form_submissions"]>;

/**
 * FormSubmission + SubmissionClaim. Every status change is a single
 * conditional UPDATE, never a read-then-write, so concurrent requests and
 * D1 (no interactive transactions) agree on who owns a row.
 */
export class Submissions {
	constructor(private readonly db: Kysely<CmsDatabase>) {}

	query() {
		return this.db.selectFrom("tbtop_form_submissions");
	}

	async create(input: { pageId: number; blockId: string; kind: string; locale: string; payload: Record<string, unknown> }): Promise<Submission> {
		const now = sqlDate(new Date());
		const id = await insertId(
			this.db,
			this.db.insertInto("tbtop_form_submissions").values({
				page_id: input.pageId,
				block_id: input.blockId,
				kind: input.kind,
				locale: input.locale,
				// MySQL's JSON column reorders keys; the order rides along as a list (OrderedPayload).
				payload: JSON.stringify(input.payload),
				payload_order: JSON.stringify(Object.keys(input.payload)),
				status: "pending",
				lead_status: "new",
				created_at: now,
				updated_at: now,
			}),
		);
		return this.findOrFail(id);
	}

	async find(id: number): Promise<Submission | null> {
		const row = await this.query().selectAll().where("id", "=", id).executeTakeFirst();
		return row === undefined ? null : submissionFromRow(row);
	}

	async findOrFail(id: number): Promise<Submission> {
		const found = await this.find(id);
		if (found === null) {
			throw new Error(`Form submission ${id} does not exist.`);
		}
		return found;
	}

	/**
	 * SubmissionClaim::take: true only for the caller that moved the row out
	 * of `from` into processing. With `untouchedBefore` the row must also be
	 * stale; the claim bumps updated_at, so a second caller finds it fresh.
	 */
	async claim(id: number, from: SubmissionStatus, untouchedBefore?: Date): Promise<boolean> {
		let qb = this.db
			.updateTable("tbtop_form_submissions")
			.set({ status: "processing", updated_at: sqlDate(new Date()) })
			.where("id", "=", id)
			.where("status", "=", from);
		if (untouchedBefore !== undefined) {
			qb = qb.where("updated_at", "<", sqlDate(untouchedBefore));
		}
		const result = await qb.executeTakeFirst();
		return Number(result.numUpdatedRows) === 1;
	}

	/** SubmissionClaim::release: guarded on processing, so it never overwrites a status someone else set. */
	async release(id: number, outcome: { status: SubmissionStatus; error: string | null }): Promise<void> {
		await this.db
			.updateTable("tbtop_form_submissions")
			.set({ status: outcome.status, error: outcome.error, updated_at: sqlDate(new Date()) })
			.where("id", "=", id)
			.where("status", "=", "processing")
			.execute();
	}

	async markHandled(id: number): Promise<void> {
		await this.db.updateTable("tbtop_form_submissions").set({ handled_at: sqlDate(new Date()) }).where("id", "=", id).execute();
	}

	/** The block's own handler failed: the row ends failed whatever state it is in. */
	async fail(id: number, error: string): Promise<void> {
		await this.db
			.updateTable("tbtop_form_submissions")
			.set({ status: "failed", error, updated_at: sqlDate(new Date()) })
			.where("id", "=", id)
			.execute();
	}

	async setLeadStatus(ids: number[], status: LeadStatus, onlyFrom?: LeadStatus): Promise<void> {
		if (ids.length === 0) {
			return;
		}
		let qb = this.db.updateTable("tbtop_form_submissions").set({ lead_status: status, updated_at: sqlDate(new Date()) }).where("id", "in", ids);
		if (onlyFrom !== undefined) {
			qb = qb.where("lead_status", "=", onlyFrom);
		}
		await qb.execute();
	}

	async delete(ids: number[]): Promise<void> {
		if (ids.length === 0) {
			return;
		}
		await this.db.deleteFrom("tbtop_form_submission_comments").where("submission_id", "in", ids).execute();
		await this.db.deleteFrom("tbtop_form_submissions").where("id", "in", ids).execute();
	}

	/** Pending rows to deliver (outbox mode), and pending/processing rows a dead worker left behind. */
	async due(limit: number): Promise<number[]> {
		const stale = sqlDate(staleBefore());
		const rows = await this.query()
			.select("id")
			.where((eb) => eb.or([eb("status", "=", "pending"), eb.and([eb("status", "=", "processing"), eb("updated_at", "<", stale)])]))
			.orderBy("id")
			.limit(limit)
			.execute();
		return rows.map((row) => Number(row.id));
	}

	async comments(id: number): Promise<{ id: number; userId: number | string; body: string; createdAt: Date | null }[]> {
		const rows = await this.db
			.selectFrom("tbtop_form_submission_comments")
			.select(["id", "user_id", "body", "created_at"])
			.where("submission_id", "=", id)
			.orderBy("id")
			.execute();
		return rows.map((row) => ({ id: Number(row.id), userId: userKey(row.user_id), body: row.body, createdAt: parseDate(row.created_at) }));
	}

	async comment(id: number, userId: number | string, body: string): Promise<void> {
		const now = sqlDate(new Date());
		await this.db
			.insertInto("tbtop_form_submission_comments")
			.values({ submission_id: id, user_id: userId, body, created_at: now, updated_at: now })
			.execute();
	}
}

export const staleBefore = (): Date => new Date(Date.now() - STALE_AFTER_MINUTES * 60_000);

/** Retryable now: failed, or pending/processing left stale (SubmissionRetrier::isRetryable). */
export const isRetryable = (submission: Submission): boolean =>
	submission.status === "failed" ||
	((submission.status === "pending" || submission.status === "processing") && submission.updatedAt !== null && submission.updatedAt < staleBefore());

export function submissionFromRow(row: Row): Submission {
	return {
		id: Number(row.id),
		pageId: row.page_id === null ? null : Number(row.page_id),
		blockId: row.block_id,
		kind: row.kind,
		locale: row.locale,
		payload: orderedPayload(row.payload, row.payload_order),
		status: row.status as SubmissionStatus,
		leadStatus: row.lead_status as LeadStatus,
		error: row.error,
		handledAt: parseDate(row.handled_at),
		createdAt: parseDate(row.created_at),
		updatedAt: parseDate(row.updated_at),
	};
}

/** OrderedPayload::get: the stored order first, then any key it does not list. */
export function orderedPayload(raw: unknown, rawOrder: unknown): Record<string, unknown> {
	const payload = decodeJson(raw);
	if (!isDict(payload)) {
		return {};
	}
	const order = decodeJson(rawOrder);
	const out: Record<string, unknown> = {};
	for (const key of Array.isArray(order) ? order : []) {
		if ((typeof key === "string" || typeof key === "number") && String(key) in payload) {
			out[String(key)] = payload[String(key)];
		}
	}
	return { ...out, ...Object.fromEntries(Object.entries(payload).filter(([key]) => !(key in out))) };
}

/** A bigint user key comes back from Postgres as a string; a uuid/ulid key stays one. */
const userKey = (raw: number | string): number | string => (typeof raw === "string" && /^\d+$/.test(raw) ? Number(raw) : raw);
