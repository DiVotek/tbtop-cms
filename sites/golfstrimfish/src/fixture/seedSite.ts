import type { Cms } from "@tbtop/cms";
import { seedFixtures } from "@tbtop/cms";
import { golfstrimFixture } from "./golfstrim";

/** Resolves fixture page keys in block data to numeric ids after the tree exists. */
export async function seedGolfstrimSite(cms: Cms): Promise<void> {
	const ids = await seedFixtures(cms, golfstrimFixture);
	if (Object.keys(ids).length === 0) {
		return;
	}
	const site = cms.site();
	const idList = (keys: string[]) => keys.map((key) => String(ids[key])).filter(Boolean);

	const categoryKeys = ["cat-dried", "cat-hot", "cat-cold", "cat-preserves"] as const;
	await patchPage(site, ids.home, (content) =>
		patchBlock(content, "CategoryCards", (data) => ({
			...data,
			items: (Array.isArray(data.items) ? data.items : []).map((item: Record<string, unknown>, index: number) => {
				const pageId = ids[categoryKeys[index] ?? ""];
				return pageId === undefined
					? item
					: {
							...item,
							link: { type: "page", page: String(pageId), label: (item.link as { label?: unknown })?.label ?? item.title },
						};
			}),
		})),
	);

	const catalog = golfstrimFixture.pages?.find((page) => page.key === "catalog");
	if (catalog === undefined) {
		return;
	}
	const allProductKeys = (catalog.children ?? []).flatMap((child) => (child.children ?? []).map((product) => product.key).filter((key): key is string => key !== undefined));
	await patchPage(site, ids.catalog, (content) => patchBlock(content, "ProductGrid", (data) => ({ ...data, products: idList(allProductKeys) })));

	for (const child of catalog.children ?? []) {
		if (child.key === undefined || ids[child.key] === undefined) {
			continue;
		}
		const productKeys = (child.children ?? []).map((product) => product.key).filter((key): key is string => key !== undefined);
		await patchPage(site, ids[child.key], (content) => patchBlock(content, "ProductGrid", (data) => ({ ...data, products: idList(productKeys) })));
	}
}

type ContentRow = { id: string; kind: string; active: boolean; sort: number; data: Record<string, unknown>; shared?: number };

async function patchPage(site: ReturnType<Cms["site"]>, pageId: number | undefined, patch: (content: ContentRow[]) => ContentRow[]): Promise<void> {
	if (pageId === undefined) {
		return;
	}
	const page = await site.pages.find(pageId);
	if (page === null) {
		return;
	}
	const content = patch((page.content as ContentRow[]) ?? []);
	await site.pages.update(pageId, { content });
}

function patchBlock(content: ContentRow[], kind: string, mapData: (data: Record<string, unknown>) => Record<string, unknown>): ContentRow[] {
	return content.map((block) => (block.kind === kind ? { ...block, data: mapData(block.data ?? {}) } : block));
}
