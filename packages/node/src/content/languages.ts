import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { bool, sqlDate } from "../support/values";

export type Language = {
	id: number;
	name: string;
	locale: string;
	og_locale: string | null;
	is_default: boolean;
	is_admin_active: boolean;
	is_frontend_active: boolean;
	sort_order: number;
};

/** The languages table, read once per Languages instance (Languages.php). */
export class Languages {
	private rows: Language[] | null = null;

	constructor(private readonly db: Kysely<CmsDatabase>) {}

	async all(): Promise<Language[]> {
		this.rows ??= (
			await this.db
				.selectFrom("tbtop_languages")
				.selectAll()
				.where("deleted_at", "is", null)
				.orderBy("sort_order")
				.orderBy("id")
				.execute()
		).map((row) => ({
			id: Number(row.id),
			name: row.name,
			locale: row.locale,
			og_locale: row.og_locale,
			is_default: bool(row.is_default),
			is_admin_active: bool(row.is_admin_active),
			is_frontend_active: bool(row.is_frontend_active),
			sort_order: Number(row.sort_order),
		}));
		return this.rows;
	}

	async default(): Promise<Language | null> {
		return (await this.all()).find((row) => row.is_default) ?? null;
	}

	async defaultLocale(): Promise<string> {
		return (await this.default())?.locale ?? "";
	}

	async frontendActive(): Promise<Language[]> {
		return (await this.all()).filter((row) => row.is_frontend_active);
	}

	async adminActive(): Promise<Language[]> {
		return (await this.all()).filter((row) => row.is_admin_active);
	}

	async find(locale: string): Promise<Language | null> {
		return (await this.all()).find((row) => row.locale === locale) ?? null;
	}

	async ogLocale(locale: string): Promise<string | null> {
		const og = (await this.find(locale))?.og_locale;
		return og !== null && og !== undefined && og !== "" ? og : null;
	}

	/** Makes `locale` the only default; the default is always admin- and frontend-active. */
	async setDefault(locale: string): Promise<void> {
		await this.db.transaction().execute(async (trx) => {
			const target = await trx.selectFrom("tbtop_languages").select("id").where("locale", "=", locale).executeTakeFirst();
			if (target === undefined) {
				throw new Error(`Language '${locale}' does not exist.`);
			}
			const now = sqlDate(new Date());
			await trx.updateTable("tbtop_languages").set({ is_default: false, updated_at: now }).where("is_default", "=", true).execute();
			await trx
				.updateTable("tbtop_languages")
				.set({ is_default: true, is_admin_active: true, is_frontend_active: true, updated_at: now })
				.where("id", "=", target.id)
				.execute();
		});
		this.rows = null;
	}

	forget(): void {
		this.rows = null;
	}
}
