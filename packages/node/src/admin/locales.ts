import type { Kysely } from "kysely";
import type { CmsConfig } from "../config";
import type { CmsDatabase } from "../db/schema";
import { flag, sqlDate } from "../support/values";

type LocaleSource = Pick<CmsConfig, "admin">;

/** AdminLocales::all(): the panel's UI locales, never empty. */
export function adminLocales(config: LocaleSource): string[] {
	const locales = config.admin?.locales ?? [];
	return locales.length === 0 ? [adminLocale(config)] : locales;
}

/** AdminLocales::default(). */
export function adminLocale(config: LocaleSource): string {
	return config.admin?.locale ?? config.admin?.locales?.[0] ?? "en";
}

/** The user key the profile rows are stored under; null for a user without one. */
export function profileKey(user: unknown): string | number | null {
	const id = (user as { id?: unknown } | null)?.id;
	return typeof id === "number" || typeof id === "string" ? id : null;
}

/**
 * AdminLocales::forAdmins(): each admin's saved UI locale when the panel
 * still offers it, else the default, in one query.
 */
export async function localesFor(config: LocaleSource & { db: Kysely<CmsDatabase> }, users: unknown[]): Promise<string[]> {
	const keys = users.map(profileKey);
	const present = keys.filter((key): key is string | number => key !== null);
	const rows =
		present.length === 0 || adminLocales(config).length < 2
			? []
			: await config.db.selectFrom("tbtop_user_profiles").select(["user_id", "locale"]).where("user_id", "in", present).execute();
	const saved = new Map(rows.map((row) => [String(row.user_id), row.locale]));
	return keys.map((key) => offered(config, key === null ? null : saved.get(String(key))));
}

function offered(config: LocaleSource, locale: string | null | undefined): string {
	return typeof locale === "string" && adminLocales(config).includes(locale) ? locale : adminLocale(config);
}

/**
 * The admin's userLocale/onLocaleChange pair over `tbtop_user_profiles.locale`,
 * so a pick in the user menu outlives the session. Left out with one locale:
 * there is nothing to pick, and the panel then skips the per-request read.
 */
export function profileLocaleHooks(config: LocaleSource & { db: Kysely<CmsDatabase> }) {
	if (adminLocales(config).length < 2) {
		return {};
	}
	const { db } = config;
	return {
		userLocale: async (user: unknown): Promise<string | null> => {
			const key = profileKey(user);
			if (key === null) {
				return null;
			}
			const row = await db.selectFrom("tbtop_user_profiles").select("locale").where("user_id", "=", key).executeTakeFirst();
			return row?.locale ?? null;
		},
		onLocaleChange: async (user: unknown, locale: string): Promise<void> => {
			const key = profileKey(user);
			if (key === null) {
				return;
			}
			const now = sqlDate(new Date());
			const updated = await db.updateTable("tbtop_user_profiles").set({ locale, updated_at: now }).where("user_id", "=", key).executeTakeFirst();
			if (Number(updated.numUpdatedRows) === 0) {
				await db.insertInto("tbtop_user_profiles").values({ user_id: key, is_owner: flag(false), locale, created_at: now, updated_at: now }).execute();
			}
		},
	};
}
