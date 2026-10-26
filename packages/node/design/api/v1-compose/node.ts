import type { Kysely } from "kysely";
import { buildSite } from "./site";

// V1 on Node/Bun: the host builds its Kysely, boots once (or `tbtop-cms migrate` on deploy), serves `fetch`.
export async function boot(db: Kysely<unknown>) {
	const site = buildSite(db);
	await site.boot();
	return site;
}
