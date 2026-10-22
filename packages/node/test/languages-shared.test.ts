import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Fixture } from "../src";
import { makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];

async function panel(fixture?: Fixture) {
	const made = await makeCms(fixture);
	const { cms, db } = made;
	const admin = createAdmin({
		prefix: "/admin",
		pages: cms.admin.pages,
		navigationGroups: cms.admin.navigationGroups,
		driver: kyselyDriver(db),
		hostRules: kyselyRules(db),
		user: () => ({ id: 1, name: "Admin" }),
		context: cms.admin.context,
	});
	let cookie = "";
	const visit = async (url: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		headers.set("cookie", cookie);
		if (init.inertia !== false) {
			headers.set("x-inertia", "true");
		}
		const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		cookie = res.headers.get("set-cookie")?.split(";")[0] ?? cookie;
		return res;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { referer: `http://x${referer}` } });
	return { ...made, admin, visit, page, post };
}

/** The notification an action or form answered with, from its JSON body or the flash on the next visit. */
async function notice(res: Response, page: (url: string) => Promise<InertiaPage>, url: string): Promise<string> {
	if (res.headers.get("content-type")?.includes("json")) {
		return JSON.stringify(await res.json());
	}
	return JSON.stringify(await page(url));
}

describe("shared blocks", () => {
	const fixture: Fixture = {
		languages: en,
		sharedBlocks: [{ key: "cta", name: "CTA", kind: "Hero", data: { title: "Shared" } }],
		pages: [{ key: "home", system: true, name: { en: "Home" } }],
	} as Fixture;

	test("a block a page places cannot be deleted; an unused one can", async () => {
		const { cms, ids } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		expect(await site.sharedBlocks.usage(block.id)).toBe(1);
		await expect(site.sharedBlocks.delete(block.id)).rejects.toThrow("Still placed in 1 place");
		expect(await site.sharedBlocks.find(block.id)).not.toBeNull();

		await site.pages.update(ids.home as number, { content: [] });
		await site.sharedBlocks.delete(block.id);
		expect(await cms.site().sharedBlocks.find(block.id)).toBeNull();
	});

	test("layouts and trashed pages count as placements; string ids count, data does not", async () => {
		const { cms, ids } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.layouts.save("header", [{ id: "h", kind: "Hero", shared: String(block.id) }]);
		const other = await site.pages.create({ type: "page", name: { en: "Other" } });
		await site.pages.update(other.id, { content: [{ id: "b", kind: "Hero", shared: block.id }] as never });
		await site.pages.delete(other.id);
		await site.pages.update(ids.home as number, { content: [{ id: "c", kind: "Hero", data: { shared: block.id } }] as never });
		expect(await site.sharedBlocks.usage(block.id)).toBe(2);
		expect((await site.sharedBlocks.usageCounts()).get(block.id)).toBe(2);
	});

	test("the admin delete refuses a placed block with an error notice", async () => {
		const { cms, ids, post, page } = await panel(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		const res = await post("/admin/shared-blocks/actions/delete", { payload: { row: { id: block.id } } }, "/admin/shared-blocks");
		expect(await notice(res, page, "/admin/shared-blocks")).toContain("Still placed in 1 place");
		expect(await cms.site().sharedBlocks.find(block.id)).not.toBeNull();
	});

	test("the list shows how many places use each block", async () => {
		const { cms, ids, visit } = await panel(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.pages.update(ids.home as number, { content: [{ id: "a", kind: "Hero", shared: block.id }] as never });
		const res = await visit("/admin/shared-blocks/tables/sharedBlocks", { inertia: false });
		const rows = ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
		expect(JSON.stringify(rows)).toContain("Placed in 1 place");
	});

	test("a save with a stale lock version is a conflict and changes nothing", async () => {
		const { cms } = await makeCms(fixture);
		const site = cms.site();
		const [block] = await site.sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		await site.sharedBlocks.update(block.id, { name: "First" }, { lockVersion: block.lockVersion });
		await expect(site.sharedBlocks.update(block.id, { name: "Second" }, { lockVersion: block.lockVersion })).rejects.toThrow("changed by someone else");
		expect((await cms.site().sharedBlocks.find(block.id))?.name).toBe("First");
	});

	test("the edit form carries the lock version and a stale submit is refused", async () => {
		const { cms, page, post } = await panel(fixture);
		const [block] = await cms.site().sharedBlocks.all();
		if (block === undefined) throw new Error("no shared block");
		const url = `/admin/shared-blocks/${block.id}/edit`;
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).sharedBlock ?? {};
		expect(record.lock_version).toBe(block.lockVersion);
		await cms.site().sharedBlocks.update(block.id, { name: "Theirs" });
		const res = await post(`${url}/forms/sharedBlock`, { ...record, shared_name: "Mine" }, url);
		expect(await notice(res, page, url)).toContain("changed by someone else");
		expect((await cms.site().sharedBlocks.find(block.id))?.name).toBe("Theirs");
	});
});
