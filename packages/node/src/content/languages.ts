import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { bool, flag, sqlDate } from "../support/values";
import { defaultOgLocale, isOgLocale } from "./localeCatalog";

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

export type LanguageInput = {
	name?: string;
	/** Blank clears it: og:locale is then omitted. */
	ogLocale?: string | null;
	isAdminActive?: boolean;
	isFrontendActive?: boolean;
	sortOrder?: number;
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
			await trx.updateTable("tbtop_languages").set({ is_default: 0, updated_at: now }).where("is_default", "=", 1).execute();
			await trx
				.updateTable("tbtop_languages")
				.set({ is_default: 1, is_admin_active: 1, is_frontend_active: 1, updated_at: now })
				.where("id", "=", target.id)
				.execute();
		});
		this.rows = null;
	}

	/**
	 * Adds a language (LanguageForm::save + LanguageObserver). The first one is
	 * the default; re-adding a soft-deleted locale restores its row, since that
	 * row still holds the unique locale; a blank og_locale comes from the catalog.
	 */
	async create(input: LanguageInput & { name: string; locale: string }): Promise<Language> {
		const ogLocale = guardOgLocale(input.ogLocale);
		const rows = await this.db.selectFrom("tbtop_languages").selectAll().where("locale", "=", input.locale).execute();
		const trashed = rows.find((row) => row.deleted_at !== null);
		if (rows.some((row) => row.deleted_at === null)) {
			throw new LanguageError(`Language ${input.locale} already exists.`);
		}
		const isDefault = (await this.all()).length === 0;
		const admin = isDefault || input.isAdminActive === true;
		const frontend = isDefault || input.isFrontendActive === true;
		if (frontend && !admin) {
			throw frontendRequiresAdmin(input.locale);
		}
		const now = sqlDate(new Date());
		const values = {
			name: input.name,
			locale: input.locale,
			og_locale: ogLocale ?? trashed?.og_locale ?? defaultOgLocale(input.locale),
			is_default: flag(isDefault),
			is_admin_active: flag(admin),
			is_frontend_active: flag(frontend),
			sort_order: input.sortOrder ?? 0,
			updated_at: now,
		};
		if (trashed === undefined) {
			await insertId(this.db, this.db.insertInto("tbtop_languages").values({ ...values, created_at: now }));
		} else {
			await this.db
				.updateTable("tbtop_languages")
				.set({ ...values, deleted_at: null })
				.where("id", "=", trashed.id)
				.execute();
		}
		return this.mustFind(input.locale);
	}

	/**
	 * Edits a language (LanguageObserver::saving): the default stays admin- and
	 * frontend-active; turning the admin off turns the site off too; turning the
	 * site on while the admin stays off is refused. The locale never changes.
	 */
	async update(id: number, input: LanguageInput): Promise<Language> {
		const row = await this.db.selectFrom("tbtop_languages").selectAll().where("id", "=", id).where("deleted_at", "is", null).executeTakeFirst();
		if (row === undefined) {
			throw new LanguageError(`Language #${id} does not exist.`);
		}
		const wasAdmin = bool(row.is_admin_active);
		const wasFrontend = bool(row.is_frontend_active);
		let admin = input.isAdminActive ?? wasAdmin;
		let frontend = input.isFrontendActive ?? wasFrontend;
		if (bool(row.is_default)) {
			admin = true;
			frontend = true;
		} else if (!admin) {
			if (frontend && !wasFrontend && !wasAdmin) {
				throw frontendRequiresAdmin(row.locale);
			}
			frontend = false;
		}
		await this.db
			.updateTable("tbtop_languages")
			.set({
				...(input.name !== undefined ? { name: input.name } : {}),
				...(input.ogLocale !== undefined ? { og_locale: guardOgLocale(input.ogLocale) } : {}),
				...(input.sortOrder !== undefined ? { sort_order: input.sortOrder } : {}),
				is_admin_active: flag(admin),
				is_frontend_active: flag(frontend),
				updated_at: sqlDate(new Date()),
			})
			.where("id", "=", id)
			.execute();
		return this.mustFind(row.locale);
	}

	private async mustFind(locale: string): Promise<Language> {
		this.rows = null;
		const language = await this.find(locale);
		if (language === null) {
			throw new LanguageError(`Language ${locale} does not exist.`);
		}
		return language;
	}

	forget(): void {
		this.rows = null;
	}
}

/** A language write LanguageObserver or LanguageForm refuses. */
export class LanguageError extends Error {}

const frontendRequiresAdmin = (locale: string) => new LanguageError(`Language '${locale}' cannot be frontend-active without being admin-active.`);

/** Blank is null; anything else must be language_TERRITORY (LanguageForm::guardOgLocale). */
function guardOgLocale(value: string | null | undefined): string | null {
	const ogLocale = (value ?? "").trim();
	if (ogLocale === "") {
		return null;
	}
	if (!isOgLocale(ogLocale)) {
		throw new LanguageError("Open Graph locale: use language_TERRITORY, e.g. en_US.");
	}
	return ogLocale;
}
