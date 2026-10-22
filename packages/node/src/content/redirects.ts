import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { sqlDate } from "../support/values";

const MAX_HOPS = 10;

export type Redirect = { id: number; locale: string | null; fromPath: string; toPath: string; statusCode: number; isAuto: boolean };

export const isExternal = (target: string): boolean => {
	const normalized = target.replaceAll("\\", "/");
	return normalized.startsWith("http://") || normalized.startsWith("https://") || normalized.startsWith("//");
};

export const normalizePath = (path: string): string => path.replace(/^[/\\]+|[/\\]+$/g, "");

/** Redirect rules: locale-specific first, then any-locale; chains followed, cycles dropped (RedirectResolver.php). */
export class Redirects {
	constructor(private readonly db: Kysely<CmsDatabase>) {}

	/** The final target (a path or an external URL) and status for a missing path, or null. */
	async resolve(path: string, locale: string): Promise<{ target: string; status: number } | null> {
		const from = normalizePath(path);
		let redirect = await this.match(from, locale);
		if (redirect === null) {
			return null;
		}
		const status = redirect.statusCode;
		const seen = [from];
		for (let hop = 0; hop < MAX_HOPS; hop++) {
			if (isExternal(redirect.toPath)) {
				return { target: redirect.toPath, status };
			}
			const to = normalizePath(redirect.toPath);
			if (seen.includes(to)) {
				return null;
			}
			const next = await this.match(to, locale);
			if (next === null) {
				return { target: to, status };
			}
			seen.push(to);
			redirect = next;
		}
		return null;
	}

	async create(input: { locale?: string | null; fromPath: string; toPath: string; statusCode?: number; isAuto?: boolean }): Promise<number> {
		const now = sqlDate(new Date());
		return insertId(
			this.db,
			this.db.insertInto("tbtop_redirects").values({
				locale: input.locale ?? null,
				from_path: normalizePath(input.fromPath),
				to_path: isExternal(input.toPath) ? input.toPath : normalizePath(input.toPath),
				status_code: input.statusCode ?? 301,
				is_auto: input.isAuto ?? false,
				created_at: now,
				updated_at: now,
			}),
		);
	}

	private async match(from: string, locale: string): Promise<Redirect | null> {
		const base = this.db.selectFrom("tbtop_redirects").selectAll().where("from_path", "=", from);
		const row =
			(await base.where("locale", "=", locale).executeTakeFirst()) ??
			(await base.where("locale", "is", null).executeTakeFirst());
		return row === undefined
			? null
			: {
					id: Number(row.id),
					locale: row.locale,
					fromPath: row.from_path,
					toPath: row.to_path,
					statusCode: Number(row.status_code),
					isAuto: Boolean(row.is_auto),
				};
	}
}
