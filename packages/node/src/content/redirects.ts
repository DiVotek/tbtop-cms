import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { flag, sqlDate } from "../support/values";

const MAX_HOPS = 10;
/** Longest chain a new or edited rule may extend (RedirectCycle::MAX_HOPS). */
const MAX_WRITE_HOPS = 20;

/** A redirect rule that fails RedirectValidator's checks; the message is user-facing. */
export class RedirectError extends Error {
	constructor(message: string) {
		super(message);
		this.name = "RedirectError";
	}
}

export type RedirectInput = { locale?: string | null; fromPath: string; toPath: string; statusCode?: number; isAuto?: boolean };

export type Redirect = { id: number; locale: string | null; fromPath: string; toPath: string; statusCode: number; isAuto: boolean };

export const isExternal = (target: string): boolean => {
	const normalized = target.replaceAll("\\", "/");
	return normalized.startsWith("http://") || normalized.startsWith("https://") || normalized.startsWith("//");
};

export const normalizePath = (path: string): string => path.replace(/^[/\\]+|[/\\]+$/g, "");

/** Redirect rules: locale-specific first, then any-locale; chains followed, cycles dropped (RedirectResolver.php). */
export class Redirects {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		/** External hosts a rule may point at (`redirects.allowedExternalHosts`); empty rejects every external target. */
		private readonly allowedExternalHosts: readonly string[] = [],
	) {}

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

	/** Validates (see `validate`) and stores a rule; throws RedirectError when it is invalid. */
	async create(input: RedirectInput): Promise<number> {
		return this.insert({ ...input, ...(await this.validate(input)) });
	}

	/**
	 * Stores a rule as given, the way a PHP seeder or model write does: the
	 * admin form's checks (`validate`) are not run. For fixtures and imports.
	 */
	async insert(rule: RedirectInput): Promise<number> {
		const now = sqlDate(new Date());
		return insertId(
			this.db,
			this.db.insertInto("tbtop_redirects").values({
				locale: rule.locale ?? null,
				from_path: normalizePath(rule.fromPath),
				to_path: rule.toPath.trim(),
				status_code: rule.statusCode ?? 301,
				is_auto: flag(rule.isAuto ?? false),
				created_at: now,
				updated_at: now,
			}),
		);
	}

	/**
	 * The normalized rule, or a RedirectError (RedirectForm::save + RedirectValidator::assert):
	 * a local, non-empty `from`; an http(s) target on an allowlisted host; status 301/302;
	 * unique per locale (NULL included); not shadowed by a page path; no loop or over-long chain.
	 * `id` is the rule being edited, left out of the uniqueness and chain checks.
	 */
	async validate(input: RedirectInput, id: number | null = null): Promise<{ locale: string | null; fromPath: string; toPath: string; statusCode: number }> {
		const locale = typeof input.locale === "string" && input.locale.trim() !== "" ? input.locale.trim() : null;
		const fromPath = normalizePath(input.fromPath);
		const rawTo = input.toPath.trim();
		if (rawTo === "" || fromPath === "") {
			throw new RedirectError("From and to paths are required.");
		}
		if (isExternal(fromPath)) {
			throw new RedirectError("From path must be a local path, not an external URL.");
		}
		const toPath = isExternal(rawTo) ? rawTo : normalizePath(rawTo);
		assertTargetAllowed(toPath, this.allowedExternalHosts);
		const statusCode = input.statusCode ?? 301;
		if (statusCode !== 301 && statusCode !== 302) {
			throw new RedirectError("Status code must be 301 or 302.");
		}
		await this.assertUnique(locale, fromPath, id);
		await this.assertNotShadowing(locale, fromPath);
		await this.assertAcyclic(locale, fromPath, toPath, id);
		return { locale, fromPath, toPath, statusCode };
	}

	private async assertUnique(locale: string | null, from: string, id: number | null): Promise<void> {
		let query = this.db.selectFrom("tbtop_redirects").select("id").where("from_path", "=", from);
		query = locale === null ? query.where("locale", "is", null) : query.where("locale", "=", locale);
		if (id !== null) {
			query = query.where("id", "!=", id);
		}
		if ((await query.executeTakeFirst()) !== undefined) {
			throw new RedirectError("A redirect already exists for this path and locale.");
		}
	}

	private async assertNotShadowing(locale: string | null, from: string): Promise<void> {
		let query = this.db.selectFrom("tbtop_page_slugs").select("id").where("path", "=", from);
		if (locale !== null) {
			query = query.where("locale", "=", locale);
		}
		if ((await query.executeTakeFirst()) !== undefined) {
			throw new RedirectError("A page already exists at this path.");
		}
	}

	/** RedirectCycle::assertAcyclic: for an any-locale rule, every locale with its own rules is walked too. */
	private async assertAcyclic(locale: string | null, from: string, to: string, id: number | null): Promise<void> {
		if (isExternal(to)) {
			return;
		}
		const others = locale === null ? await this.db.selectFrom("tbtop_redirects").select("locale").distinct().where("locale", "is not", null).execute() : [];
		const locales = locale === null ? [null, ...others.map((row) => row.locale)] : [locale];
		for (const candidate of locales) {
			await this.assertAcyclicFor(candidate, from, to, id);
		}
	}

	private async assertAcyclicFor(locale: string | null, from: string, to: string, id: number | null): Promise<void> {
		const seen = [from];
		let current = normalizePath(to);
		for (let hop = 0; hop < MAX_WRITE_HOPS; hop++) {
			if (seen.includes(current)) {
				throw new RedirectError("This redirect would create a loop.");
			}
			seen.push(current);
			const next = await this.nextTarget(locale, current, id);
			if (next === null) {
				return;
			}
			current = next;
		}
		throw new RedirectError("Redirect chain is too long.");
	}

	private async nextTarget(locale: string | null, path: string, id: number | null): Promise<string | null> {
		let base = this.db.selectFrom("tbtop_redirects").select("to_path").where("from_path", "=", path);
		if (id !== null) {
			base = base.where("id", "!=", id);
		}
		const row =
			(locale === null ? undefined : await base.where("locale", "=", locale).executeTakeFirst()) ??
			(await base.where("locale", "is", null).executeTakeFirst());
		return row === undefined || isExternal(row.to_path) ? null : normalizePath(row.to_path);
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

/** RedirectValidator::assertToAllowed: an external target needs an http(s) URL with a host on the allowlist. */
function assertTargetAllowed(to: string, allowedHosts: readonly string[]): void {
	if (!isExternal(to)) {
		return;
	}
	const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(to)?.[1]?.toLowerCase();
	const host = scheme === "http" || scheme === "https" ? hostOf(to) : "";
	if (host === "") {
		throw new RedirectError("External redirect target must be a valid http(s) URL with a host.");
	}
	if (!allowedHosts.map((allowed) => allowed.toLowerCase()).includes(host.toLowerCase())) {
		throw new RedirectError("External redirect host is not allowlisted.");
	}
}

function hostOf(url: string): string {
	try {
		return new URL(url).hostname;
	} catch {
		return "";
	}
}
