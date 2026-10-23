import { type Kysely, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";
import { AUDIT_SUBJECTS, AuditLog } from "./audit";

export type SharedBlock = { id: number; name: string; kind: string; data: Dict; lockVersion: number };

/** Reusable block data that page rows point at with `{ id, kind, shared: <id> }` (SharedBlocks.php). */
export class SharedBlocks {
	private byId: Map<number, SharedBlock> | null = null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly audit: AuditLog = new AuditLog(db),
	) {}

	async all(): Promise<SharedBlock[]> {
		return [...(await this.load()).values()];
	}

	async find(id: number): Promise<SharedBlock | null> {
		return (await this.load()).get(id) ?? null;
	}

	/** The data a block row renders: its own, or its shared block's when the kinds match. */
	async dataOf(row: Dict, kind: string): Promise<Dict | null> {
		const ref = refOf(row);
		if (ref === null) {
			return isDict(row.data) ? row.data : {};
		}
		const shared = await this.find(ref);
		return shared === null || shared.kind !== kind ? null : shared.data;
	}

	async create(input: { name: string; kind: string; data: Dict }): Promise<number> {
		return this.audit.track(AUDIT_SUBJECTS.sharedBlock, null, () => this.insert(input), { idOf: (id) => id });
	}

	private async insert(input: { name: string; kind: string; data: Dict }): Promise<number> {
		const now = sqlDate(new Date());
		const id = await insertId(
			this.db,
			this.db.insertInto("tbtop_shared_blocks").values({
				name: input.name,
				kind: input.kind,
				data: JSON.stringify(input.data),
				created_at: now,
				updated_at: now,
			}),
		);
		this.byId = null;
		return id;
	}

	/**
	 * Updates a shared block. With `lockVersion` the write only lands when the
	 * row still has it (SharedBlockForm::update); a stale one throws SharedBlockConflictError.
	 */
	async update(id: number, input: { name?: string; data?: Dict }, options: { lockVersion?: number } = {}): Promise<void> {
		await this.audit.track(AUDIT_SUBJECTS.sharedBlock, id, () => this.write(id, input, options));
	}

	private async write(id: number, input: { name?: string; data?: Dict }, options: { lockVersion?: number }): Promise<void> {
		let qb = this.db
			.updateTable("tbtop_shared_blocks")
			.set({
				...(input.name !== undefined ? { name: input.name } : {}),
				...(input.data !== undefined ? { data: JSON.stringify(input.data) } : {}),
				lock_version: sql<number>`lock_version + 1`,
				updated_at: sqlDate(new Date()),
			})
			.where("id", "=", id);
		if (options.lockVersion !== undefined) {
			qb = qb.where("lock_version", "=", options.lockVersion);
		}
		const result = await qb.executeTakeFirst();
		this.byId = null;
		if (options.lockVersion !== undefined && Number(result.numUpdatedRows) === 0) {
			throw new SharedBlockConflictError(id);
		}
	}

	/** Deletes an unplaced block; one still placed anywhere throws SharedBlockInUseError (SharedBlocksPage::deleteAction). */
	async delete(id: number): Promise<void> {
		await this.audit.track(AUDIT_SUBJECTS.sharedBlock, id, () =>
			this.db.transaction().execute(async (trx) => {
				const inUse = (await placements(trx)).get(id) ?? 0;
				if (inUse > 0) {
					throw new SharedBlockInUseError(id, inUse);
				}
				await trx.deleteFrom("tbtop_shared_blocks").where("id", "=", id).execute();
			}),
		);
		this.byId = null;
	}

	/** How many pages (trashed included) and layout slots place this block (SharedBlockUsage::using). */
	async usage(id: number): Promise<number> {
		return (await placements(this.db)).get(id) ?? 0;
	}

	/** Placement counts by shared block id (SharedBlockUsage::counts). */
	async usageCounts(): Promise<Map<number, number>> {
		return placements(this.db);
	}

	private async load(): Promise<Map<number, SharedBlock>> {
		if (this.byId === null) {
			const rows = await this.db.selectFrom("tbtop_shared_blocks").selectAll().execute();
			this.byId = new Map(
				rows.map((row) => {
					const data = decodeJson(row.data);
					return [
						Number(row.id),
						{
							id: Number(row.id),
							name: row.name,
							kind: row.kind,
							data: isDict(data) ? data : {},
							lockVersion: Number(row.lock_version),
						},
					];
				}),
			);
		}
		return this.byId;
	}
}

/**
 * Containers per shared block id, read from each page's and layout's top-level
 * rows. Trashed pages count: a restore brings their pointers back.
 */
async function placements(db: Kysely<CmsDatabase>): Promise<Map<number, number>> {
	const pages = await db.selectFrom("tbtop_pages").select("content").execute();
	const layouts = await db.selectFrom("tbtop_layouts").select("content").execute();
	const counts = new Map<number, number>();
	for (const row of [...pages, ...layouts]) {
		const content = decodeJson(row.content);
		const refs = new Set((Array.isArray(content) ? content : []).map(refOf).filter((ref): ref is number => ref !== null));
		for (const ref of refs) {
			counts.set(ref, (counts.get(ref) ?? 0) + 1);
		}
	}
	return counts;
}

export class SharedBlockConflictError extends Error {
	constructor(readonly sharedBlockId: number) {
		super(`Shared block #${sharedBlockId} was changed by someone else.`);
	}
}

export class SharedBlockInUseError extends Error {
	constructor(
		readonly sharedBlockId: number,
		readonly places: number,
	) {
		super(`Still placed in ${places} ${places === 1 ? "place" : "places"} — remove it there first.`);
	}
}

/** The shared block id a row points at, if any. */
export function refOf(row: unknown): number | null {
	const ref = isDict(row) ? row.shared : null;
	if (typeof ref === "number" && Number.isInteger(ref)) {
		return ref;
	}
	return typeof ref === "string" && /^\d+$/.test(ref) ? Number(ref) : null;
}
