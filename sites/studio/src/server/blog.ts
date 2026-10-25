import { createServerFn } from "@tanstack/react-start";
import { site } from "./cms";
import { pageText } from "./pageText";

export type JournalCard = {
	id: number;
	name: string;
	summary: string | null;
	url: string;
	tags: string[];
	readingMinutes: number | null;
	publishedLabel: string | null;
};

export type JournalIndex = {
	posts: JournalCard[];
	total: number;
	page: number;
	pageSize: number;
	totalPages: number;
	allTags: string[];
};

const PAGE_SIZE = 2;

export async function journalIndexForCategory(
	categoryId: number,
	locale: string,
	fallback: string,
	page: number,
	tag?: string,
): Promise<JournalIndex> {
	const { cms } = await site();
	const children = await cms.site().pages.children(categoryId, { live: true, indexable: true });
	const cards: JournalCard[] = [];
	const tagSet = new Set<string>();
	for (const child of children) {
		const fields = (child.fields ?? {}) as { tags?: string[]; readingMinutes?: number | null };
		const tags = Array.isArray(fields.tags) ? fields.tags : [];
		for (const t of tags) {
			tagSet.add(t);
		}
		cards.push({
			id: child.id,
			name: pageText(child, "name", locale, fallback),
			summary: pageText(child, "summary", locale, fallback) || null,
			url: (await cms.site().pageUrl(child, locale)) ?? "",
			tags,
			readingMinutes: typeof fields.readingMinutes === "number" ? fields.readingMinutes : null,
			publishedLabel: child.publishedAt?.toISOString().slice(0, 10) ?? null,
		});
	}
	const filtered = tag ? cards.filter((card) => card.tags.includes(tag)) : cards;
	const current = Math.max(1, page);
	const total = filtered.length;
	const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
	const start = (current - 1) * PAGE_SIZE;
	return {
		posts: filtered.slice(start, start + PAGE_SIZE),
		total,
		page: current,
		pageSize: PAGE_SIZE,
		totalPages,
		allTags: [...tagSet].sort(),
	};
}

/** Client navigations can call the same index builder through a server function. */
export const loadJournalIndex = createServerFn({ method: "GET" })
	.validator((input: { categoryId: number; locale: string; fallback: string; page?: number; tag?: string }) => input)
	.handler(async ({ data }) => journalIndexForCategory(data.categoryId, data.locale, data.fallback, data.page ?? 1, data.tag));
