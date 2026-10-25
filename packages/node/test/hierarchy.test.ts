import { describe, expect, test } from "bun:test";
import { createAdmin } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { get, makeCms } from "./support";
import { formParentId } from "../src/admin/pageLeafType";

const en = (name: string) => ({ en: name });

describe("page hierarchy (PageHierarchy)", () => {
	test("an article cannot sit at the root", async () => {
		const { cms } = await makeCms();
		await expect(cms.site().pages.create({ type: "article", name: en("Loose") })).rejects.toThrow("requires a parent");
	});

	test("a category does not hold a plain page", async () => {
		const { cms, ids } = await makeCms();
		await expect(cms.site().pages.create({ type: "page", parentId: ids.blog as number, name: en("Odd") })).rejects.toThrow(
			"cannot contain a [page] child",
		);
	});

	test("an unregistered type and a missing parent are refused", async () => {
		const { cms, ids } = await makeCms();
		const pages = cms.site().pages;
		await expect(pages.create({ type: "nope", name: en("X") })).rejects.toThrow("[nope] is not registered");
		await expect(pages.create({ type: "article", parentId: 9999, name: en("X") })).rejects.toThrow("missing or trashed");
		await expect(pages.update(ids["first-post"] as number, { parentId: null })).rejects.toThrow("requires a parent");
	});

	test("moving a category under its own descendant is refused, and does not hang", async () => {
		const { cms } = await makeCms();
		const pages = cms.site().pages;
		const a = await pages.create({ type: "category", name: en("A") });
		const b = await pages.create({ type: "category", parentId: a.id, name: en("B") });
		await expect(pages.update(a.id, { parentId: b.id })).rejects.toThrow("under itself or one of its descendants");
		await expect(pages.update(a.id, { parentId: a.id })).rejects.toThrow("under itself");
		expect((await pages.find(a.id))?.parentId).toBeNull();
	}, 5000);

	test("a slug sync over a cycle already in the data terminates", async () => {
		const { cms, db } = await makeCms();
		const pages = cms.site().pages;
		const a = await pages.create({ type: "category", name: en("A") });
		const b = await pages.create({ type: "category", parentId: a.id, name: en("B") });
		await db.updateTable("tbtop_pages").set({ parent_id: b.id }).where("id", "=", a.id).execute();
		await pages.update(b.id, { name: en("B2") }, { slugs: { en: "b2" } });
		expect(await pages.slugs.pathFor(b.id, "en")).toContain("b2");
	}, 5000);

	test("maxDepth counts the moved subtree", async () => {
		const { cms } = await makeCms();
		const pages = cms.site().pages;
		const c1 = await pages.create({ type: "category", name: en("C1") });
		const c2 = await pages.create({ type: "category", parentId: c1.id, name: en("C2") });
		await pages.create({ type: "category", parentId: c2.id, name: en("C3") });
		await expect(pages.create({ type: "category", parentId: (await pages.children(c2.id))[0]?.id ?? 0, name: en("C4") })).rejects.toThrow(
			"Nesting depth 4 exceeds maximum 3",
		);
		const d1 = await pages.create({ type: "category", name: en("D1") });
		await pages.create({ type: "category", parentId: d1.id, name: en("D2") });
		await expect(pages.update(d1.id, { parentId: c2.id })).rejects.toThrow("Nesting depth 4 exceeds maximum 3");
	});

	test("a type change must still allow the existing children", async () => {
		const { cms, ids } = await makeCms();
		await expect(cms.site().pages.update(ids.blog as number, { type: "page" })).rejects.toThrow(
			"[page] cannot contain existing [article] child",
		);
	});
});

describe("page guards (PageObserver)", () => {
	test("a page with live children cannot be deleted; once they are gone it can", async () => {
		const { cms, ids } = await makeCms();
		const pages = cms.site().pages;
		await expect(pages.delete(ids.blog as number)).rejects.toThrow("has child pages");
		expect((await cms.loadPage(get("/blog/first-post"))).type).toBe("page");
		await pages.delete(ids["first-post"] as number);
		await pages.delete(ids["scheduled-post"] as number);
		await pages.delete(ids.blog as number);
		expect(await pages.find(ids.blog as number)).toBeNull();
	});

	test("a system page cannot be deleted nor change status", async () => {
		const { cms, ids } = await makeCms();
		const pages = cms.site().pages;
		const home = ids.home as number;
		await expect(pages.delete(home)).rejects.toThrow("System pages cannot be deleted");
		await expect(pages.update(home, { status: "draft" })).rejects.toThrow("status of a system page cannot be changed");
		await pages.update(home, { status: "published", name: en("Home") });
	});

	test("an error page's class is immutable", async () => {
		const { cms } = await makeCms();
		const pages = cms.site().pages;
		const page = await pages.create({ type: "page", name: en("Not found"), errorClass: "4xx", isSystem: true });
		await expect(pages.update(page.id, { errorClass: "5xx" })).rejects.toThrow("cannot be changed after creation");
	});

	test("the type/subtype pair must be a base and one of its subtypes", async () => {
		const { cms, ids } = await makeCms(undefined, { pageTypes: [{ key: "news", base: "article" }] });
		const pages = cms.site().pages;
		const blog = ids.blog as number;
		await expect(pages.create({ type: "news", parentId: blog, name: en("N") })).rejects.toThrow("news is a subtype of article");
		await expect(pages.create({ type: "article", subtype: "page", parentId: blog, name: en("N") })).rejects.toThrow(
			"page is not a subtype of article",
		);
		const ok = await pages.create({ type: "article", subtype: "news", parentId: blog, name: en("N") });
		expect(ok.subtype).toBe("news");
	});

	test("the pages list's delete-permanently action reports the refusal as a notification", async () => {
		const { cms, db, ids } = await makeCms();
		const admin = createAdmin({
			prefix: "/admin",
			pages: cms.admin.pages,
			navigationGroups: cms.admin.navigationGroups,
			driver: kyselyDriver(db),
			hostRules: kyselyRules(db),
			user: () => ({ id: 1, name: "Admin" }),
			context: cms.admin.context,
		});
		// The blog's posts go to the trash first, then the blog: its trashed children still hold it.
		const pages = cms.site().pages;
		for (const id of [ids["first-post"], ids["scheduled-post"], ids.blog]) {
			if (id !== undefined) {
				await pages.delete(id);
			}
		}
		const res = await admin.handle(
			new Request("http://x/admin/pages/actions/pageForceDelete", {
				method: "POST",
				body: JSON.stringify({ payload: { row: { _key: ids.blog } } }),
				headers: { "content-type": "application/json", "x-inertia": "true", referer: "http://x/admin/pages" },
			}),
		);
		expect(JSON.stringify(await res?.json())).toContain("has child pages");
		expect(await db.selectFrom("tbtop_pages").select("id").where("id", "=", ids.blog as number).executeTakeFirst()).toBeDefined();
	});

	test("the parent select offers categories and parents that accept the page's type", async () => {
		const { cms, db, ids } = await makeCms();
		const pages = cms.site().pages;
		const inner = await pages.create({ type: "category", parentId: ids.blog as number, name: en("Inner") });
		const admin = createAdmin({
			prefix: "/admin",
			pages: cms.admin.pages,
			navigationGroups: cms.admin.navigationGroups,
			driver: kyselyDriver(db),
			hostRules: kyselyRules(db),
			user: () => ({ id: 1, name: "Admin" }),
			context: cms.admin.context,
		});
		const options = async (id: number) => {
			const res = await admin.handle(new Request(`http://x/admin/pages/${id}/edit`, { headers: { "x-inertia": "true" } }));
			const tree = JSON.stringify(await res?.json());
			const match = tree.match(/"label":"Parent","options":(\[.*?\])/);
			// In sort order, as PageForm::parentQuery; compared as a set.
			return match === null ? null : (JSON.parse(match[1] ?? "[]") as { value: string }[]).map((o) => Number(o.value)).sort((a, b) => a - b);
		};
		// A page is offered categories too: under one it becomes an article (PageLeafType).
		expect(await options(ids.about as number)).toEqual([ids.blog as number, inner.id]);
		expect(await options(ids["first-post"] as number)).toEqual([ids.blog as number, inner.id]);
		expect(await options(ids.blog as number)).toEqual([]);
	});
});

describe("a submitted parent id", () => {
	test("only a positive decimal id names a parent", () => {
		expect(["7", 7, " 12 "].map(formParentId)).toEqual([7, 7, 12]);
		expect(["", "  ", "none", "0", "1e3", "0x10", "-3", "2.5", null, undefined].map(formParentId)).toEqual(Array(10).fill(null));
	});
});
