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

/** AdminLocales::default(): `admin.locale` when the list has it (or there is no list), else the list's first. */
export function adminLocale(config: LocaleSource): string {
	const { locale, locales = [] } = config.admin ?? {};
	return locale !== undefined && (locales.length === 0 || locales.includes(locale)) ? locale : (locales[0] ?? "en");
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
	const rows = present.length === 0 || adminLocales(config).length < 2 ? [] : await profileRows(config.db, present);
	const saved = new Map(rows.map((row) => [String(row.user_id), row.locale]));
	return keys.map((key) => offered(config, key === null ? null : saved.get(String(key))));
}

/**
 * A failed read (say a string id against a bigint `user_id`, when the
 * migration's `userKey` does not match the auth's) leaves the default
 * locale: a profile read never blocks a delivery or a panel request.
 */
async function profileRows(db: Kysely<CmsDatabase>, keys: (string | number)[]): Promise<{ user_id: string | number; locale: string | null }[]> {
	try {
		return await db.selectFrom("tbtop_user_profiles").select(["user_id", "locale"]).where("user_id", "in", keys).execute();
	} catch (error) {
		console.warn("cms.admin_locale.read_failed", { error });
		return [];
	}
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
			const [row] = await profileRows(db, [key]);
			return row?.locale ?? null;
		},
		onLocaleChange: async (user: unknown, locale: string): Promise<void> => {
			const key = profileKey(user);
			if (key === null || !adminLocales(config).includes(locale)) {
				return;
			}
			const now = sqlDate(new Date());
			try {
				const updated = await db.updateTable("tbtop_user_profiles").set({ locale, updated_at: now }).where("user_id", "=", key).executeTakeFirst();
				if (Number(updated.numUpdatedRows) === 0) {
					await db.insertInto("tbtop_user_profiles").values({ user_id: key, is_owner: flag(false), locale, created_at: now, updated_at: now }).execute();
				}
			} catch (error) {
				// The pick still holds for the session; only its saving failed.
				console.warn("cms.admin_locale.save_failed", { error });
			}
		},
	};
}
