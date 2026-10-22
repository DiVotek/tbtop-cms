import type { Cms } from "../cms";
import type { Translations } from "../content/page";
import { insertId } from "../support/db";
import { flag, sqlDate } from "../support/values";

export type FixtureBlock = {
	kind: string;
	id?: string;
	active?: boolean;
	data?: Record<string, unknown>;
	/** A key of `sharedBlocks`: the row points at that shared block. */
	shared?: string;
};

export type FixturePage = {
	/** A name other parts of the fixture can point at (redirect targets, links). */
	key?: string;
	type?: string;
	subtype?: string;
	name: Translations;
	title?: Translations;
	heading?: Translations;
	summary?: Translations;
	/** Plain text per locale, stored as a Lexical document. */
	body?: Record<string, string>;
	status?: "draft" | "published";
	publishedAt?: string;
	unpublishAt?: string;
	/** The root system page is the home page (empty slug). */
	system?: boolean;
	indexable?: boolean;
	slug?: Record<string, string>;
	seo?: Record<string, unknown>;
	image?: string;
	fields?: Record<string, unknown>;
	blocks?: FixtureBlock[];
	children?: FixturePage[];
};

export type Fixture = {
	languages: {
		locale: string;
		name: string;
		ogLocale?: string;
		default?: boolean;
		admin?: boolean;
		frontend?: boolean;
	}[];
	settings?: Record<string, unknown>;
	sharedBlocks?: { key: string; name: string; kind: string; data: Record<string, unknown> }[];
	pages?: FixturePage[];
	layout?: { header?: FixtureBlock[]; footer?: FixtureBlock[] };
	redirects?: { from: string; to: string; status?: number; locale?: string | null }[];
};

const lexical = (text: string) => ({
	root: {
		type: "root",
		children: text.split(/\n{2,}/).map((paragraph) => ({ type: "paragraph", children: [{ type: "text", text: paragraph, format: 0 }] })),
	},
});

/**
 * Seeds a fixture through the package's own write paths (slugs, shared
 * references), so the result is what an editor would have produced. It runs
 * once: a database that already has pages is left alone. Returns page ids by key.
 */
export async function seedFixtures(cms: Cms, fixture: Fixture): Promise<Record<string, number>> {
	const site = cms.site();
	const db = cms.config.db;
	const existing = await site.pages.query().select("id").limit(1).executeTakeFirst();
	if (existing !== undefined) {
		return {};
	}
	const now = sqlDate(new Date());
	for (const [index, language] of fixture.languages.entries()) {
		if ((await site.languages.find(language.locale)) !== null) {
			continue;
		}
		const isDefault = language.default === true;
		await insertId(
			db,
			db.insertInto("tbtop_languages").values({
				name: language.name,
				locale: language.locale,
				og_locale: language.ogLocale ?? null,
				is_default: flag(isDefault),
				is_admin_active: flag(isDefault || language.admin !== false),
				is_frontend_active: flag(isDefault || language.frontend !== false),
				sort_order: index,
				created_at: now,
				updated_at: now,
			}),
		);
		site.languages.forget();
	}
	await site.settings.setMany(fixture.settings ?? {});

	const shared = new Map<string, number>();
	for (const block of fixture.sharedBlocks ?? []) {
		shared.set(block.key, await site.sharedBlocks.create(block));
	}
	let counter = 0;
	const rows = (blocks: FixtureBlock[] = []) =>
		blocks.map((block, sort) => {
			const id = block.id ?? `fx${String(++counter).padStart(6, "0")}`;
			const base = { id, kind: block.kind, active: block.active ?? true, sort };
			if (block.shared === undefined) {
				return { ...base, data: block.data ?? {} };
			}
			const ref = shared.get(block.shared);
			if (ref === undefined) {
				throw new Error(`Fixture block points at unknown shared block "${block.shared}".`);
			}
			return { ...base, shared: ref };
		});

	const ids: Record<string, number> = {};
	const create = async (page: FixturePage, parentId: number | null, sortOrder: number): Promise<void> => {
		const created = await site.pages.create(
			{
				type: page.type ?? "page",
				subtype: page.subtype ?? null,
				parentId,
				name: page.name,
				title: page.title ?? {},
				heading: page.heading ?? {},
				summary: page.summary ?? {},
				body: Object.fromEntries(Object.entries(page.body ?? {}).map(([locale, text]) => [locale, lexical(text)])),
				status: page.status ?? "published",
				publishedAt: page.publishedAt === undefined ? null : new Date(page.publishedAt),
				unpublishAt: page.unpublishAt === undefined ? null : new Date(page.unpublishAt),
				isSystem: page.system ?? false,
				isIndexable: page.indexable ?? true,
				seo: page.seo ?? {},
				image: page.image ?? null,
				fields: page.fields ?? null,
				sortOrder,
				content: rows(page.blocks),
			},
			{ slugs: page.slug ?? {} },
		);
		if (page.key !== undefined) {
			ids[page.key] = created.id;
		}
		for (const [index, child] of (page.children ?? []).entries()) {
			await create(child, created.id, index);
		}
	};
	for (const [index, page] of (fixture.pages ?? []).entries()) {
		await create(page, null, index);
	}
	for (const slot of ["header", "footer"] as const) {
		const blocks = fixture.layout?.[slot];
		if (blocks !== undefined) {
			await site.layouts.save(slot, rows(blocks));
		}
	}
	for (const redirect of fixture.redirects ?? []) {
		await site.redirects.create({ fromPath: redirect.from, toPath: redirect.to, statusCode: redirect.status ?? 301, locale: redirect.locale ?? null });
	}
	return ids;
}
