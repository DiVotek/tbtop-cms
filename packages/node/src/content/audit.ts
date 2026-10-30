import { currentContext } from "@tbtop/admin";
import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";

const LOG_NAME = "tbtop-cms";

/** What a store's rows are audited as (LogsCmsActivity's auditFields/auditLabel/markers/blocks). */
export type AuditSubject = {
	type: "page" | "redirect" | "language" | "shared_block" | "layout" | "entry";
	table: "tbtop_pages" | "tbtop_redirects" | "tbtop_languages" | "tbtop_shared_blocks" | "tbtop_layouts" | "tbtop_entries";
	fields: readonly string[];
	label: (row: Dict) => string;
	/** Changed, but too large or private to copy: the name is kept, the values are not. */
	markers?: readonly string[];
	/** Block lists summarised to `{id, kind}` rows. */
	blocks?: readonly string[];
};

const firstText = (raw: unknown): string | null => {
	const value = decodeJson(raw);
	const text = isDict(value) ? Object.values(value).find((item) => typeof item === "string" && item !== "") : value;
	return typeof text === "string" && text !== "" ? text : null;
};

export const AUDIT_SUBJECTS = {
	page: {
		type: "page",
		table: "tbtop_pages",
		fields: ["type", "subtype", "parent_id", "status", "published_at", "unpublish_at", "name", "heading", "summary", "body", "image", "content", "fields", "seo", "is_indexable", "is_system", "error_class", "sort_order"],
		markers: ["body", "content", "fields", "seo"],
		label: (row) => firstText(row.name) ?? `Page #${String(row.id)}`,
	},
	redirect: {
		type: "redirect",
		table: "tbtop_redirects",
		fields: ["locale", "from_path", "to_path", "status_code", "is_auto"],
		label: (row) => (typeof row.from_path === "string" && row.from_path !== "" ? row.from_path : `Redirect #${String(row.id)}`),
	},
	language: {
		type: "language",
		table: "tbtop_languages",
		fields: ["name", "locale", "is_default", "is_admin_active", "is_frontend_active", "sort_order"],
		label: (row) => String(row.name || row.locale),
	},
	sharedBlock: { type: "shared_block", table: "tbtop_shared_blocks", fields: ["name", "kind", "data"], label: (row) => String(row.name) },
	entry: { type: "entry", table: "tbtop_entries", fields: ["data", "status"], label: (row) => (typeof row.title === "string" && row.title !== "" ? row.title : `Entry #${String(row.id)}`) },
	layout: { type: "layout", table: "tbtop_layouts", fields: ["slot", "content"], blocks: ["content"], label: (row) => String(row.slot) },
} as const satisfies Record<string, AuditSubject>;

export type AuditEntry = {
	id: number;
	event: string;
	subjectType: string | null;
	subjectId: number | null;
	label: string;
	causerId: string | number | null;
	changedFields: string[];
	changes: { old?: Dict; attributes?: Dict };
	context: Dict;
	createdAt: string | null;
};

/** The signed-in admin of this request, as CurrentAdmin; null outside the admin (seeders, the frontend, a cron). */
function causer(): string | number | null {
	const user = currentContext().user as { id?: unknown } | null;
	return typeof user?.id === "number" || typeof user?.id === "string" ? user.id : null;
}

/** A stored value as data; an empty object or list counts as null, so `{}` written over NULL is no change. */
function plain(value: unknown): unknown {
	const data = typeof value === "string" && /^[[{]/.test(value) ? decodeJson(value) : value;
	const empty = data === undefined || (Array.isArray(data) ? data.length === 0 : isDict(data) && Object.keys(data).length === 0);
	return empty ? null : data;
}

function blockSummary(value: unknown): unknown {
	return Array.isArray(value) ? value.filter(isDict).map((row) => ({ id: row.id ?? null, kind: row.kind ?? null })) : value;
}

/**
 * The audit trail in `activity_log` (spatie activitylog + AuditContext):
 * a store write made by a signed-in admin records its event, the changed
 * fields and their old and new values. Writes outside the admin record
 * nothing, as in PHP. Every tracked write, audited or not, then calls
 * `onWrite` (InvalidatesContentCache: the content cache's bump).
 */
export class AuditLog {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly onWrite: () => Promise<void> = async () => {},
	) {}

	/** A content write made outside `track` (a drag reorder): the same `onWrite`. */
	changed(): Promise<void> {
		return this.onWrite();
	}

	/**
	 * Runs a write and records it: created, updated (only when an audited
	 * field changed), deleted (row gone or soft-deleted) or restored. `id`
	 * is the row before the write, or read from the result for a create.
	 */
	async track<T>(subject: AuditSubject, id: number | null, run: () => Promise<T>, options: { idOf?: (result: T) => number; event?: string; context?: Dict } = {}): Promise<T> {
		const by = causer();
		if (by === null) {
			const result = await run();
			await this.onWrite();
			return result;
		}
		const before = id === null ? null : await this.row(subject, id);
		const result = await run();
		await this.onWrite();
		const afterId = id ?? options.idOf?.(result) ?? null;
		const after = afterId === null ? null : await this.row(subject, afterId);
		const event = options.event ?? inferEvent(before, after);
		const changes = diff(subject, before, after, event);
		if (event === "updated" && changes.fields.length === 0) {
			return result;
		}
		const now = sqlDate(new Date());
		await this.db
			.insertInto("activity_log")
			.values({
				log_name: LOG_NAME,
				description: `tbtop-cms.audit.events.${event}`,
				subject_type: subject.type,
				subject_id: afterId ?? id,
				event,
				causer_type: "user",
				causer_id: by as number,
				attribute_changes: JSON.stringify({ old: changes.old, attributes: changes.attributes }),
				properties: JSON.stringify({ subject_label: subject.label(after ?? before ?? { id: afterId ?? id }), changed_fields: changes.fields, context: options.context ?? {} }),
				created_at: now,
				updated_at: now,
			})
			.execute();
		return result;
	}

	/** Newest first; narrowed to one subject type, or one row of it, when given. */
	async list(options: { limit?: number; subjectType?: string; subjectId?: number } = {}): Promise<AuditEntry[]> {
		let qb = this.db.selectFrom("activity_log").selectAll().where("log_name", "=", LOG_NAME);
		if (options.subjectType !== undefined) {
			qb = qb.where("subject_type", "=", options.subjectType);
		}
		if (options.subjectId !== undefined) {
			qb = qb.where("subject_id", "=", options.subjectId);
		}
		const rows = await qb.orderBy("created_at", "desc").orderBy("id", "desc").limit(options.limit ?? 50).execute();
		return rows.map((row) => {
			const properties = decodeJson(row.properties);
			const props = isDict(properties) ? properties : {};
			const changes = decodeJson(row.attribute_changes);
			return {
				id: Number(row.id),
				event: row.event ?? "",
				subjectType: row.subject_type,
				subjectId: row.subject_id === null ? null : Number(row.subject_id),
				label: typeof props.subject_label === "string" ? props.subject_label : "",
				causerId: row.causer_id === null ? null : typeof row.causer_id === "string" && /^\d+$/.test(row.causer_id) ? Number(row.causer_id) : row.causer_id,
				changedFields: Array.isArray(props.changed_fields) ? props.changed_fields.map(String) : [],
				changes: isDict(changes) ? (changes as AuditEntry["changes"]) : {},
				context: isDict(props.context) ? props.context : {},
				createdAt: row.created_at === null ? null : String(row.created_at),
			};
		});
	}

	/** `activitylog:clean tbtop-cms --days=N`: entries older than `days` (default 365) go; how many. */
	async prune(days = 365, now = new Date()): Promise<number> {
		const cutoff = sqlDate(new Date(now.getTime() - Math.max(1, days) * 86_400_000));
		const result = await this.db.deleteFrom("activity_log").where("log_name", "=", LOG_NAME).where("created_at", "<", cutoff).executeTakeFirst();
		return Number(result.numDeletedRows);
	}

	private async row(subject: AuditSubject, id: number): Promise<Dict | null> {
		const row = await this.db.selectFrom(subject.table).selectAll().where("id", "=", id).executeTakeFirst();
		return (row as Dict | undefined) ?? null;
	}
}

function inferEvent(before: Dict | null, after: Dict | null): string {
	if (before === null) {
		return "created";
	}
	if (after === null || (before.deleted_at == null && after.deleted_at != null)) {
		return "deleted";
	}
	return before.deleted_at != null && after.deleted_at == null ? "restored" : "updated";
}

/** The audited fields that differ, old and new values; markers keep only the name, block lists a summary. */
function diff(subject: AuditSubject, before: Dict | null, after: Dict | null, event: string) {
	const fields = subject.fields.filter((field) => {
		if (event === "created") {
			return plain(after?.[field]) !== null;
		}
		if (event === "deleted") {
			return false;
		}
		return JSON.stringify(plain(before?.[field])) !== JSON.stringify(plain(after?.[field]));
	});
	const old: Dict = {};
	const attributes: Dict = {};
	for (const field of fields) {
		if (subject.markers?.includes(field)) {
			continue;
		}
		const shape = subject.blocks?.includes(field) ? blockSummary : (value: unknown) => value;
		if (event !== "created") {
			old[field] = shape(plain(before?.[field] ?? null));
		}
		attributes[field] = shape(plain(after?.[field] ?? null));
	}
	return { fields, old, attributes };
}
