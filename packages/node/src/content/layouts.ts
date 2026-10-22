import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { decodeJson, sqlDate } from "../support/values";

export const SLOTS = ["header", "footer"] as const;
export type Slot = (typeof SLOTS)[number];

/** The header and footer block lists (Layout.php, LayoutResolver::content). */
export class Layouts {
	constructor(private readonly db: Kysely<CmsDatabase>) {}

	async content(): Promise<Record<Slot, unknown[]>> {
		const rows = await this.db.selectFrom("tbtop_layouts").select(["slot", "content"]).execute();
		const out = { header: [], footer: [] } as Record<Slot, unknown[]>;
		for (const row of rows) {
			const content = decodeJson(row.content);
			if ((SLOTS as readonly string[]).includes(row.slot) && Array.isArray(content)) {
				out[row.slot as Slot] = content;
			}
		}
		return out;
	}

	async save(slot: Slot, content: unknown[]): Promise<void> {
		const now = sqlDate(new Date());
		const encoded = JSON.stringify(content);
		const existing = await this.db.selectFrom("tbtop_layouts").select("id").where("slot", "=", slot).executeTakeFirst();
		if (existing === undefined) {
			await this.db.insertInto("tbtop_layouts").values({ slot, content: encoded, created_at: now, updated_at: now }).execute();
			return;
		}
		await this.db.updateTable("tbtop_layouts").set({ content: encoded, updated_at: now }).where("id", "=", existing.id).execute();
	}
}
