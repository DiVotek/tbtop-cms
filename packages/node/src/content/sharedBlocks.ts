import { type Kysely, sql } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { type Dict, decodeJson, isDict, sqlDate } from "../support/values";

export type SharedBlock = { id: number; name: string; kind: string; data: Dict; lockVersion: number };

/** Reusable block data that page rows point at with `{ id, kind, shared: <id> }` (SharedBlocks.php). */
export class SharedBlocks {
	private byId: Map<number, SharedBlock> | null = null;

	constructor(private readonly db: Kysely<CmsDatabase>) {}

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

	async update(id: number, input: { name?: string; data?: Dict }): Promise<void> {
		await this.db
			.updateTable("tbtop_shared_blocks")
			.set({
				...(input.name !== undefined ? { name: input.name } : {}),
				...(input.data !== undefined ? { data: JSON.stringify(input.data) } : {}),
				lock_version: sql<number>`lock_version + 1`,
				updated_at: sqlDate(new Date()),
			})
			.where("id", "=", id)
			.execute();
		this.byId = null;
	}

	async delete(id: number): Promise<void> {
		await this.db.deleteFrom("tbtop_shared_blocks").where("id", "=", id).execute();
		this.byId = null;
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

/** The shared block id a row points at, if any. */
export function refOf(row: unknown): number | null {
	const ref = isDict(row) ? row.shared : null;
	if (typeof ref === "number" && Number.isInteger(ref)) {
		return ref;
	}
	return typeof ref === "string" && /^\d+$/.test(ref) ? Number(ref) : null;
}
