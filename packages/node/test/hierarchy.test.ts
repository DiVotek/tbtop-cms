import { describe, expect, test } from "bun:test";
import { createAdmin } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { makeCms } from "./support";

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

describe("admin parent select", () => {
	test("the parent select offers only parents that accept the page's type", async () => {
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
			const match = tree.match(/"label":"Parent","options":(\[.*?\])\},"meta":\{\},"name":"parent_id"/);
			return match === null ? null : (JSON.parse(match[1] ?? "[]") as { value: string }[]).map((o) => Number(o.value));
		};
		expect(await options(ids.about as number)).toEqual([]);
		expect(await options(ids["first-post"] as number)).toEqual([ids.blog as number, inner.id]);
		expect(await options(ids.blog as number)).toEqual([]);
	});
});
