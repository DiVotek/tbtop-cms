import { type Kysely, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";
import { AUDIT_SUBJECTS, AuditLog } from "./audit";
import type { Collections } from "./collections";

export const ENTRY_DRAFT = "draft";
export const ENTRY_PUBLISHED = "published";
export type EntryStatus = typeof ENTRY_DRAFT | typeof ENTRY_PUBLISHED;

/** One `tbtop_entries` row as stored: `data` keeps locale maps. */
export type Entry = { id: number; collection: string; data: Dict; title: string; status: EntryStatus; sortOrder: number; lockVersion: number; updatedAt: string | null };

type EntryRow = { id: number | string | bigint; collection: string; data: unknown; title: string; status: string; sort_order: number | string | bigint; lock_version: number | string | bigint; updated_at?: unknown };

/**
 * The entries of every collection (the Entry model). Each write sets the
 * `title` column from the collection's title field in the default locale,
 * goes in the audit trail when an admin makes it, and calls `changed()`,
 * which bumps the content cache.
 */
export class Entries {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly collections: Collections,
		private readonly defaultLocale: () => Promise<string>,
		private readonly onChange: () => Promise<void>,
		private readonly audit: AuditLog = new AuditLog(db),
	) {}

	/** Entries changed outside the store (a drag reorder through the admin driver): drop the cached lists. */
	changed(): Promise<void> {
		return this.onChange();
	}

	async find(id: number): Promise<Entry | null> {
		const row = await this.db.selectFrom("tbtop_entries").selectAll().where("id", "=", id).executeTakeFirst();
		return row === undefined ? null : entryOf(row);
	}

	/** A collection's entries in the editor's order, drafts included unless `published`. */
	async list(collection: string, options: { published?: boolean } = {}): Promise<Entry[]> {
		let query = this.db.selectFrom("tbtop_entries").selectAll().where("collection", "=", collection);
		if (options.published === true) {
			query = query.where("status", "=", ENTRY_PUBLISHED);
		}
		return (await query.orderBy("sort_order").orderBy("id").execute()).map(entryOf);
	}

	/** Every published entry, by collection, each in the editor's order: one query for all the collections a render reads. */
	async published(): Promise<Map<string, Entry[]>> {
		const rows = await this.db.selectFrom("tbtop_entries").selectAll().where("status", "=", ENTRY_PUBLISHED).orderBy("sort_order").orderBy("id").execute();
		const out = new Map<string, Entry[]>();
		for (const entry of rows.map(entryOf)) {
			out.set(entry.collection, [...(out.get(entry.collection) ?? []), entry]);
		}
		return out;
	}

	/** Appended after the collection's last entry unless `sortOrder` is given. */
	async create(input: { collection: string; data: Dict; status?: EntryStatus; sortOrder?: number }): Promise<number> {
		const collection = this.collections.get(input.collection);
		const id = await this.audit.track(
			AUDIT_SUBJECTS.entry,
			null,
			async () => {
				const now = sqlDate(new Date());
				return insertId(
					this.db,
					this.db.insertInto("tbtop_entries").values({
						collection: collection.key,
						data: JSON.stringify(input.data),
						title: await this.titleOf(collection.key, input.data),
						status: input.status ?? ENTRY_DRAFT,
						sort_order: input.sortOrder ?? (await this.nextSort(collection.key)),
						created_at: now,
						updated_at: now,
					}),
				);
			},
			{ idOf: (created) => created },
		);
		await this.changed();
		return id;
	}

	/** With `lockVersion` the write lands only while the row still has it; a stale one throws EntryConflictError. */
	async update(id: number, input: { data?: Dict; status?: EntryStatus }, options: { lockVersion?: number } = {}): Promise<void> {
		const current = await this.find(id);
		if (current === null) {
			throw new Error(`Entry #${id} does not exist.`);
		}
		await this.audit.track(AUDIT_SUBJECTS.entry, id, async () => {
			let query = this.db
				.updateTable("tbtop_entries")
				.set({
					...(input.data === undefined ? {} : { data: JSON.stringify(input.data), title: await this.titleOf(current.collection, input.data) }),
					...(input.status === undefined ? {} : { status: input.status }),
					lock_version: sql<number>`lock_version + 1`,
					updated_at: sqlDate(new Date()),
				})
				.where("id", "=", id);
			if (options.lockVersion !== undefined) {
				query = query.where("lock_version", "=", options.lockVersion);
			}
			const result = await query.executeTakeFirst();
			if (options.lockVersion !== undefined && Number(result.numUpdatedRows) === 0) {
				throw new EntryConflictError(id);
			}
		});
		await this.changed();
	}

	async delete(id: number): Promise<void> {
		await this.audit.track(AUDIT_SUBJECTS.entry, id, async () => {
			await this.db.deleteFrom("tbtop_entries").where("id", "=", id).execute();
		});
		await this.changed();
	}

	/** The title field's text in the default locale, else in any locale, cut to the column. */
	private async titleOf(collection: string, data: Dict): Promise<string> {
		const value = data[this.collections.get(collection).titleField];
		const fallback = await this.defaultLocale();
		const text = typeof value === "string" ? value : isDict(value) ? [value[fallback], ...Object.values(value)].find((v) => typeof v === "string" && v.trim() !== "") : null;
		return typeof text === "string" ? [...text.trim()].slice(0, 255).join("") : "";
	}

	private async nextSort(collection: string): Promise<number> {
		const row = await this.db
			.selectFrom("tbtop_entries")
			.select((eb) => eb.fn.max("sort_order").as("max"))
			.where("collection", "=", collection)
			.executeTakeFirst();
		return row?.max === null || row?.max === undefined ? 0 : Number(row.max) + 1;
	}
}

export function entryOf(row: EntryRow): Entry {
	const data = decodeJson(row.data);
	return {
		id: Number(row.id),
		collection: row.collection,
		data: isDict(data) ? data : {},
		title: row.title,
		status: row.status === ENTRY_PUBLISHED ? ENTRY_PUBLISHED : ENTRY_DRAFT,
		sortOrder: Number(row.sort_order),
		lockVersion: Number(row.lock_version),
		updatedAt: row.updated_at === null || row.updated_at === undefined ? null : String(row.updated_at),
	};
}

export class EntryConflictError extends Error {
	constructor(readonly entryId: number) {
		super(`Entry #${entryId} was changed by someone else.`);
	}
}
