import type { ExpressionBuilder, Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { ContentCache } from "../support/cache";
import { insertId } from "../support/db";
import { bool, flag, sqlDate } from "../support/values";
import { AUDIT_SUBJECTS, AuditLog } from "./audit";
import { defaultOgLocale, isOgLocale, localeName } from "./localeCatalog";

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

/**
 * The languages table, read once per Languages instance (Languages.php);
 * with `cache` its reads go through it and every write bumps it.
 */
export class Languages {
	private rows: Language[] | null = null;

	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly cache: { store: ContentCache; read: boolean } | null = null,
		private readonly audit: AuditLog = new AuditLog(db),
	) {}

	async all(): Promise<Language[]> {
		this.rows ??= this.cache?.read === true ? await this.cache.store.remember("languages", () => this.query()) : await this.query();
		return this.rows;
	}

	private async query(): Promise<Language[]> {
		return (
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

	/**
	 * Makes `locale` the only default; the default is always admin- and frontend-active.
	 * Resolves true when this turned the site on for `locale`: the caller then backfills
	 * its slugs (`pages.slugs.backfillLocale`), as ContentEventsWiring does on save in PHP.
	 */
	async setDefault(locale: string): Promise<boolean> {
		const target = await this.db.selectFrom("tbtop_languages").select("id").where("locale", "=", locale).executeTakeFirst();
		const activated = await this.audit.track(AUDIT_SUBJECTS.language, target === undefined ? null : Number(target.id), () => this.makeDefault(locale));
		await this.changed();
		return activated;
	}

	/**
	 * One UPDATE flips the default, so no reader sees two defaults or none,
	 * without a transaction: D1 has none (kysely-d1 refuses to open one).
	 * TRUE/FALSE literals are a boolean on Postgres and 1/0 on SQLite and MySQL.
	 */
	private async makeDefault(locale: string): Promise<boolean> {
		const target = await this.db.selectFrom("tbtop_languages").select(["id", "is_frontend_active"]).where("locale", "=", locale).executeTakeFirst();
		if (target === undefined) {
			throw new Error(`Language '${locale}' does not exist.`);
		}
		const isTarget = (eb: ExpressionBuilder<CmsDatabase, "tbtop_languages">) => eb("id", "=", target.id);
		await this.db
			.updateTable("tbtop_languages")
			.set((eb) => ({
				is_default: eb.case().when(isTarget(eb)).then(eb.lit(true)).else(eb.lit(false)).end().$castTo<0 | 1>(),
				is_admin_active: eb.case().when(isTarget(eb)).then(eb.lit(true)).else(eb.ref("is_admin_active")).end().$castTo<0 | 1>(),
				is_frontend_active: eb.case().when(isTarget(eb)).then(eb.lit(true)).else(eb.ref("is_frontend_active")).end().$castTo<0 | 1>(),
				updated_at: sqlDate(new Date()),
			}))
			.where((eb) => eb.or([eb("is_default", "=", 1), isTarget(eb)]))
			.execute();
		return !bool(target.is_frontend_active);
	}

	/**
	 * Adds a language (LanguageForm::save + LanguageObserver). The first one is
	 * the default; re-adding a soft-deleted locale restores its row, since that
	 * row still holds the unique locale; a blank og_locale comes from the catalog.
	 * Without a name the catalog's native name is used; a locale the catalog
	 * does not list needs one.
	 */
	async create(input: LanguageInput & { locale: string }): Promise<Language> {
		const trashed = await this.db.selectFrom("tbtop_languages").select("id").where("locale", "=", input.locale).where("deleted_at", "is not", null).executeTakeFirst();
		return this.audit.track(AUDIT_SUBJECTS.language, trashed === undefined ? null : Number(trashed.id), () => this.insert(input), { idOf: (language) => language.id });
	}

	private async insert(input: LanguageInput & { locale: string }): Promise<Language> {
		const ogLocale = guardOgLocale(input.ogLocale);
		const name = input.name ?? localeName(input.locale);
		if (name === null || name.trim() === "") {
			throw new LanguageError("Unknown locale — pick a language from the list.");
		}
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
			name,
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
		return this.audit.track(AUDIT_SUBJECTS.language, id, () => this.write(id, input));
	}

	private async write(id: number, input: LanguageInput): Promise<Language> {
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

	/**
	 * Moves a language to the trash (SoftDeletes): its URLs stop answering, its
	 * translations stay. The default is refused (LanguageObserver::deleting).
	 */
	async delete(id: number): Promise<void> {
		const row = await this.row(id, false);
		if (bool(row.is_default)) {
			throw defaultNotDeletable(row.locale);
		}
		await this.audit.track(AUDIT_SUBJECTS.language, id, () => this.db.updateTable("tbtop_languages").set({ deleted_at: sqlDate(new Date()) }).where("id", "=", id).execute());
		await this.changed();
	}

	/** Brings a trashed language back with its flags as they were. */
	async restore(id: number): Promise<Language> {
		const row = await this.row(id, true);
		await this.audit.track(AUDIT_SUBJECTS.language, id, () =>
			this.db.updateTable("tbtop_languages").set({ deleted_at: null, updated_at: sqlDate(new Date()) }).where("id", "=", id).execute(),
		);
		return this.mustFind(row.locale);
	}

	/** Removes a trashed language for good; its translations stay in the page rows, unreachable. */
	async forceDelete(id: number): Promise<void> {
		const row = await this.row(id, true);
		if (bool(row.is_default)) {
			throw defaultNotDeletable(row.locale);
		}
		await this.audit.track(AUDIT_SUBJECTS.language, id, () => this.db.deleteFrom("tbtop_languages").where("id", "=", id).execute());
		await this.changed();
	}

	/** A live (`trashed` false) or trashed row by id, or a LanguageError. */
	private async row(id: number, trashed: boolean) {
		const row = await this.db
			.selectFrom("tbtop_languages")
			.selectAll()
			.where("id", "=", id)
			.where("deleted_at", trashed ? "is not" : "is", null)
			.executeTakeFirst();
		if (row === undefined) {
			throw new LanguageError(`Language #${id} does not exist${trashed ? " in the trash" : ""}.`);
		}
		return row;
	}

	/** After a write: create, update and restore read their row back through here. */
	private async mustFind(locale: string): Promise<Language> {
		await this.changed();
		const language = await this.find(locale);
		if (language === null) {
			throw new LanguageError(`Language ${locale} does not exist.`);
		}
		return language;
	}

	forget(): void {
		this.rows = null;
	}

	private async changed(): Promise<void> {
		this.rows = null;
		await this.cache?.store.bump();
	}
}

/** A language write LanguageObserver or LanguageForm refuses. */
export class LanguageError extends Error {}

const defaultNotDeletable = (locale: string) => new LanguageError(`Default language '${locale}' cannot be deleted. Reassign default first.`);

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

/**
 * The install step (`cms:install` running LanguageSeeder): a database with no
 * live language gets `locale` as its default, named from the catalog. A
 * trashed row of that locale is revived, since it still holds the unique
 * locale. Resolves true when it wrote a row.
 */
export async function ensureDefaultLanguage(db: Kysely<CmsDatabase>, locale = "en"): Promise<boolean> {
	const live = await db.selectFrom("tbtop_languages").select("id").where("deleted_at", "is", null).limit(1).executeTakeFirst();
	if (live !== undefined) {
		return false;
	}
	const name = localeName(locale);
	if (name === null) {
		throw new LanguageError(`Unknown locale '${locale}': the default language must be one the catalog lists.`);
	}
	const now = sqlDate(new Date());
	const values = {
		name,
		locale,
		og_locale: defaultOgLocale(locale),
		is_default: flag(true),
		is_admin_active: flag(true),
		is_frontend_active: flag(true),
		sort_order: 0,
		updated_at: now,
	};
	const trashed = await db.selectFrom("tbtop_languages").select("id").where("locale", "=", locale).executeTakeFirst();
	if (trashed === undefined) {
		await insertId(db, db.insertInto("tbtop_languages").values({ ...values, created_at: now }));
	} else {
		await db
			.updateTable("tbtop_languages")
			.set({ ...values, deleted_at: null })
			.where("id", "=", trashed.id)
			.execute();
	}
	return true;
}
