import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import { insertId } from "../support/db";
import { capSlug, slugify } from "../support/slug";
import { sqlDate } from "../support/values";
import { type Page, pageFromRow } from "./page";
import { clearAt, recordMove } from "./slugRedirects";

type SlugRow = { id: number; slug: string; path: string };

/**
 * One slug and full path per page and frontend locale (PageSlugWriter.php).
 * The slug comes from the override, else the stored slug, else the name;
 * a taken path gets `-2`, `-3`…; a change cascades to the children. A moved
 * path leaves a 301 auto redirect behind (PageSlugRedirects.php).
 */
export class PageSlugs {
	constructor(
		private readonly db: Kysely<CmsDatabase>,
		private readonly locales: () => Promise<string[]>,
		private readonly defaultLocale: () => Promise<string>,
	) {}

	/** The page's path in a locale; null when it has none there. */
	async pathFor(pageId: number, locale: string): Promise<string | null> {
		const row = await this.db
			.selectFrom("tbtop_page_slugs")
			.select("path")
			.where("page_id", "=", pageId)
			.where("locale", "=", locale)
			.executeTakeFirst();
		return row?.path ?? null;
	}

	/** Paths of several pages in a locale, by page id. */
	async pathsFor(pageIds: number[], locale: string): Promise<Map<number, string>> {
		if (pageIds.length === 0) {
			return new Map();
		}
		const rows = await this.db
			.selectFrom("tbtop_page_slugs")
			.select(["page_id", "path"])
			.where("locale", "=", locale)
			.where("page_id", "in", pageIds)
			.execute();
		return new Map(rows.map((row) => [Number(row.page_id), row.path]));
	}

	/** Every locale's path of a page. */
	async allFor(pageId: number): Promise<Record<string, string>> {
		const rows = await this.db
			.selectFrom("tbtop_page_slugs")
			.select(["locale", "path"])
			.where("page_id", "=", pageId)
			.execute();
		return Object.fromEntries(rows.map((row) => [row.locale, row.path]));
	}

	/** Every locale's path of each page, in one query. */
	async allForMany(pageIds: number[]): Promise<Map<number, Record<string, string>>> {
		const paths = new Map<number, Record<string, string>>();
		if (pageIds.length === 0) {
			return paths;
		}
		const rows = await this.db.selectFrom("tbtop_page_slugs").select(["page_id", "locale", "path"]).where("page_id", "in", pageIds).execute();
		for (const row of rows) {
			const id = Number(row.page_id);
			paths.set(id, { ...paths.get(id), [row.locale]: row.path });
		}
		return paths;
	}

	/** The page id at a path; null when none. */
	async pageAt(path: string, locale: string): Promise<number | null> {
		const row = await this.db
			.selectFrom("tbtop_page_slugs")
			.select("page_id")
			.where("locale", "=", locale)
			.where("path", "=", path)
			.executeTakeFirst();
		return row === undefined ? null : Number(row.page_id);
	}

	/** `visited` cuts a parent cycle already in the data, so the cascade ends. */
	async sync(page: Page, overrides: Record<string, string> = {}, visited: Set<number> = new Set()): Promise<boolean> {
		if (page.errorClass !== null || visited.has(page.id)) {
			return false;
		}
		visited.add(page.id);
		let changed = false;
		for (const locale of await this.locales()) {
			if (await this.writeRow(page, locale, overrides[locale] ?? "")) {
				changed = true;
			}
		}
		if (changed) {
			await this.cascade(page.id, visited);
		}
		return changed;
	}

	/** Writes slugs for a newly frontend-active locale across the whole tree. */
	async backfillLocale(locale: string): Promise<void> {
		let level = await this.pagesWhere(null);
		while (level.length > 0) {
			for (const page of level) {
				if (page.errorClass === null) {
					await this.writeRow(page, locale, "");
				}
			}
			const next: Page[] = [];
			for (const page of level) {
				next.push(...(await this.pagesWhere(page.id)));
			}
			level = next;
		}
	}

	async remove(pageId: number): Promise<void> {
		await this.db.deleteFrom("tbtop_page_slugs").where("page_id", "=", pageId).execute();
	}

	private async cascade(parentId: number, visited: Set<number>): Promise<void> {
		for (const child of await this.pagesWhere(parentId)) {
			await this.sync(child, {}, visited);
		}
	}

	private async pagesWhere(parentId: number | null): Promise<Page[]> {
		const qb = this.db.selectFrom("tbtop_pages").selectAll().where("deleted_at", "is", null);
		const rows = await (parentId === null ? qb.where("parent_id", "is", null) : qb.where("parent_id", "=", parentId))
			.orderBy("id")
			.execute();
		return rows.map(pageFromRow);
	}

	private async writeRow(page: Page, locale: string, override: string): Promise<boolean> {
		const existing = await this.findRow(page.id, locale);
		const base = await this.baseFor(page, locale, existing, override);
		const parentPath = page.parentId === null ? "" : ((await this.pathFor(page.parentId, locale)) ?? "");
		const [slug, path] = await this.free(page.id, locale, parentPath, base);
		if (existing !== null && existing.slug === slug && existing.path === path) {
			return false;
		}
		const now = sqlDate(new Date());
		if (existing === null) {
			await insertId(
				this.db,
				this.db
					.insertInto("tbtop_page_slugs")
					.values({ page_id: page.id, locale, slug, path, created_at: now, updated_at: now }),
			);
		} else {
			await this.db.updateTable("tbtop_page_slugs").set({ slug, path, updated_at: now }).where("id", "=", existing.id).execute();
			if (existing.path !== path) {
				await recordMove(this.db, locale, existing.path, path);
			}
		}
		await clearAt(this.db, locale, path);
		return true;
	}

	private async free(pageId: number, locale: string, parentPath: string, base: string): Promise<[string, string]> {
		let slug = base;
		for (let suffix = 2; ; suffix++) {
			const path = join(parentPath, slug);
			const holder = await this.pageAt(path, locale);
			if (holder === null || holder === pageId) {
				return [slug, path];
			}
			slug = `${base}-${suffix}`;
		}
	}

	private async findRow(pageId: number, locale: string): Promise<SlugRow | null> {
		const row = await this.db
			.selectFrom("tbtop_page_slugs")
			.select(["id", "slug", "path"])
			.where("page_id", "=", pageId)
			.where("locale", "=", locale)
			.executeTakeFirst();
		return row === undefined ? null : { id: Number(row.id), slug: row.slug, path: row.path };
	}

	private async baseFor(page: Page, locale: string, existing: SlugRow | null, override: string): Promise<string> {
		if (override !== "" && !page.isSystem) {
			const slug = slugify(override);
			if (slug !== "") {
				return capSlug(slug);
			}
		}
		return existing !== null ? existing.slug : this.slugBase(page, locale);
	}

	private async slugBase(page: Page, locale: string): Promise<string> {
		if (page.isSystem && page.parentId === null) {
			return "";
		}
		const own = page.name[locale];
		const name = typeof own === "string" && own !== "" ? own : page.name[await this.defaultLocale()];
		const slug = slugify(typeof name === "string" ? name : "");
		return slug === "" ? `page-${page.id}` : capSlug(slug);
	}
}

function join(parentPath: string, slug: string): string {
	if (parentPath === "") {
		return slug;
	}
	return slug === "" ? parentPath : `${parentPath}/${slug}`;
}
