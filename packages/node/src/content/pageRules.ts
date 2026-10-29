import type { Kysely } from "kysely";
import type { CmsDatabase } from "../db/schema";
import type { PageTypes } from "./pageTypes";

/** A page write the tree rules or the PageObserver guards refuse (PHP: DomainException). */
export class PageRuleError extends Error {}

/** The page as it will be stored: `id` is null on create. */
export type PageDraft = { id: number | null; type: string; subtype: string | null; parentId: number | null };

const MAX_STEPS = 64;

/** PageHierarchy::assert. `typeChanged`/`parentChanged` only matter for an existing page. */
export async function assertHierarchy(
	db: Kysely<CmsDatabase>,
	types: PageTypes,
	page: PageDraft,
	changed: { type: boolean; parent: boolean } = { type: true, parent: true },
): Promise<void> {
	if (!types.has(page.type)) {
		throw new PageRuleError(`Page type [${page.type}] is not registered.`);
	}
	if (page.id !== null && changed.type) {
		await assertChildrenAllowed(db, types, page.id, page.type);
	}
	if (page.parentId === null) {
		if (types.get(page.type).requiresParent) {
			throw new PageRuleError(`Page type [${page.type}] requires a parent.`);
		}
		return;
	}
	await assertParentAllowsChild(db, types, page, page.parentId);
	const chain = await ancestorIds(db, page.parentId);
	if (page.id !== null && chain.includes(page.id)) {
		throw new PageRuleError("A page cannot be placed under itself or one of its descendants.");
	}
	await assertNestingDepth(db, types, page, chain.length + 1, page.id !== null && changed.parent);
}

/** PageTypePairProblem::of: `type` is a base, `subtype` one of its subtypes. */
export function assertTypePair(types: PageTypes, type: string, subtype: string | null): void {
	if (types.has(type) && types.get(type).base !== type) {
		const base = types.get(type).base;
		throw new PageRuleError(`${type} is a subtype of ${base}: store ${base} in the type and ${type} in the subtype.`);
	}
	if (subtype === null || subtype === "") {
		return;
	}
	if (subtype === type || !types.has(subtype) || types.get(subtype).base !== type) {
		throw new PageRuleError(`${subtype} is not a subtype of ${type}.`);
	}
}

/** Whether a page of `parentType` may hold a `childType` child (the parent select's filter). */
export function allowsChild(types: PageTypes, parentType: string, childType: string): boolean {
	return types.has(parentType) && types.get(parentType).allowedChildren.includes(childType);
}

/** Ids of a page and its live descendants, cycles cut. */
export async function subtreeIds(db: Kysely<CmsDatabase>, id: number): Promise<Set<number>> {
	const seen = new Set<number>([id]);
	let frontier = [id];
	while (frontier.length > 0) {
		const rows = await liveChildren(db, frontier);
		frontier = rows.map((row) => Number(row.id)).filter((child) => !seen.has(child));
		for (const child of frontier) {
			seen.add(child);
		}
	}
	return seen;
}

async function assertChildrenAllowed(db: Kysely<CmsDatabase>, types: PageTypes, id: number, type: string): Promise<void> {
	const allowed = types.get(type).allowedChildren;
	for (const child of await liveChildren(db, [id])) {
		if (!allowed.includes(child.type)) {
			throw new PageRuleError(`Page type [${type}] cannot contain existing [${child.type}] child.`);
		}
	}
}

async function assertParentAllowsChild(db: Kysely<CmsDatabase>, types: PageTypes, page: PageDraft, parentId: number): Promise<void> {
	if (page.id !== null && parentId === page.id) {
		throw new PageRuleError("A page cannot be placed under itself or one of its descendants.");
	}
	const parent = await db.selectFrom("tbtop_pages").select(["type", "deleted_at"]).where("id", "=", parentId).executeTakeFirst();
	if (parent === undefined || parent.deleted_at !== null) {
		throw new PageRuleError(`Parent page [${parentId}] is missing or trashed.`);
	}
	if (!types.has(parent.type)) {
		throw new PageRuleError(`Page type [${parent.type}] is not registered.`);
	}
	if (!allowsChild(types, parent.type, page.type)) {
		throw new PageRuleError(`Page type [${parent.type}] cannot contain a [${page.type}] child.`);
	}
}

/** AncestorChain::forPage as ids: the live chain from `parentId` up (nearest first), cycles cut. */
async function ancestorIds(db: Kysely<CmsDatabase>, parentId: number): Promise<number[]> {
	const chain: number[] = [];
	let next: number | null = parentId;
	while (next !== null && chain.length < MAX_STEPS && !chain.includes(next)) {
		const row = await db
			.selectFrom("tbtop_pages")
			.select(["id", "parent_id"])
			.where("id", "=", next)
			.where("deleted_at", "is", null)
			.executeTakeFirst();
		if (row === undefined) {
			break;
		}
		chain.push(Number(row.id));
		next = row.parent_id === null ? null : Number(row.parent_id);
	}
	return chain;
}

async function assertNestingDepth(db: Kysely<CmsDatabase>, types: PageTypes, page: PageDraft, depth: number, moved: boolean): Promise<void> {
	assertDepth(types, page.type, depth);
	if (!moved || page.id === null) {
		return;
	}
	// One query per level of the moved subtree, not per descendant.
	const seen = new Set<number>([page.id]);
	let frontier = [page.id];
	let level = depth;
	while (frontier.length > 0) {
		level++;
		const rows = (await liveChildren(db, frontier)).filter((row) => !seen.has(Number(row.id)));
		for (const row of rows) {
			assertDepth(types, row.type, level);
			seen.add(Number(row.id));
		}
		frontier = rows.map((row) => Number(row.id));
	}
}

function assertDepth(types: PageTypes, key: string, depth: number): void {
	// An unregistered descendant has no limit; a leaf type does not extend nesting.
	if (!types.has(key)) {
		return;
	}
	const type = types.get(key);
	if (type.allowedChildren.length === 0) {
		return;
	}
	if (depth > type.maxDepth) {
		throw new PageRuleError(`Nesting depth ${depth} exceeds maximum ${type.maxDepth} for type [${key}].`);
	}
}

async function liveChildren(db: Kysely<CmsDatabase>, parentIds: number[]) {
	return db
		.selectFrom("tbtop_pages")
		.select(["id", "type"])
		.where("parent_id", "in", parentIds)
		.where("deleted_at", "is", null)
		.execute();
}
