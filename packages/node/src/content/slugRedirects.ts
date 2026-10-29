import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { flag, sqlDate } from "../support/values";
import { normalizePath } from "./redirects";

/**
 * A page path moved (RedirectWriter::recordMove): auto rules that pointed at
 * the old path follow it, and the old path gets a 301 auto rule to the new one.
 */
export async function recordMove(db: Kysely<CmsDatabase>, locale: string, fromPath: string, toPath: string): Promise<void> {
	const from = normalizePath(fromPath);
	const to = normalizePath(toPath);
	if (from === "" || from === to) {
		return;
	}
	const now = sqlDate(new Date());
	await db
		.updateTable("tbtop_redirects")
		.set({ to_path: to, updated_at: now })
		.where("locale", "=", locale)
		.where("is_auto", "=", flag(true))
		.where("to_path", "=", from)
		.execute();
	const existing = await db.selectFrom("tbtop_redirects").select("id").where("locale", "=", locale).where("from_path", "=", from).executeTakeFirst();
	if (existing === undefined) {
		await db
			.insertInto("tbtop_redirects")
			.values({ locale, from_path: from, to_path: to, status_code: 301, is_auto: flag(true), created_at: now, updated_at: now })
			.execute();
		return;
	}
	await db.updateTable("tbtop_redirects").set({ to_path: to, status_code: 301, is_auto: flag(true), updated_at: now }).where("id", "=", existing.id).execute();
}

/** A page now lives at this path (RedirectWriter::clearAt): a rule from it would never answer, so it goes. */
export async function clearAt(db: Kysely<CmsDatabase>, locale: string, path: string): Promise<void> {
	await db.deleteFrom("tbtop_redirects").where("locale", "=", locale).where("from_path", "=", normalizePath(path)).execute();
}
