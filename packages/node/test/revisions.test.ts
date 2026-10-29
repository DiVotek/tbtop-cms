import { describe, expect, test } from "bun:test";
import { createAdmin } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, pages: [{ key: "about", name: { en: "About" }, heading: { en: "First" } }] };

async function edit(cms: Awaited<ReturnType<typeof makeCms>>["cms"], id: number, input: Parameters<ReturnType<typeof cms.site>["pages"]["update"]>[1]) {
	const page = await cms.site().pages.find(id);
	return cms.site().pages.update(id, input, { lockVersion: page?.lockVersion, by: 7 });
}

describe("page revisions", () => {
	test("create, then each editorial save, is a revision; a status-only save is not", async () => {
		const { cms, ids } = await makeCms(fixture);
		const id = ids.about as number;
		await edit(cms, id, { heading: { en: "Second" } });
		await edit(cms, id, { status: "published" });
		await edit(cms, id, { sortOrder: 3 });

		const revisions = await cms.site().pages.revisions.list(id);
		expect(revisions.map((revision) => [revision.kind, revision.payload.heading])).toEqual([
			["save", { en: "Second" }],
			["create", { en: "First" }],
		]);
		expect(revisions[0]?.userId).toBe(7);
	});

	test("restore puts the content back, keeps status and slugs, and is recorded", async () => {
		const { cms, ids } = await makeCms(fixture);
		const id = ids.about as number;
		const site = cms.site();
		const [created] = await site.pages.revisions.list(id);
		await edit(cms, id, { heading: { en: "Second" }, content: [{ id: "a", type: "Hero", data: {} }], status: "published" });
		const slugs = await site.pages.slugs.allFor(id);

		const restored = await site.pages.restore(id, created?.id as number, { by: 7 });
		expect(restored.heading).toEqual({ en: "First" });
		expect(restored.content).toEqual([]);
		expect(restored.status).toBe("published");
		expect(await site.pages.slugs.allFor(id)).toEqual(slugs);
		expect((await site.pages.revisions.list(id))[0]?.kind).toBe("restore");
	});

	test("only the newest `keep` revisions stay; keep 0 records none", async () => {
		const { cms, ids } = await makeCms(fixture, { revisions: { keep: 2 } });
		for (const title of ["B", "C", "D"]) {
			await edit(cms, ids.about as number, { heading: { en: title } });
		}
		expect((await cms.site().pages.revisions.list(ids.about as number)).map((revision) => revision.payload.heading)).toEqual([{ en: "D" }, { en: "C" }]);

		const off = await makeCms(fixture, { revisions: { keep: 0 } });
		await edit(off.cms, off.ids.about as number, { heading: { en: "B" } });
		expect(await off.db.selectFrom("tbtop_page_revisions").select("id").execute()).toEqual([]);
	});

	test("a revision of another page cannot be restored", async () => {
		const { cms, ids } = await makeCms({ languages: en, pages: [{ key: "a", name: { en: "A" } }, { key: "b", name: { en: "B" } }] });
		const [ofB] = await cms.site().pages.revisions.list(ids.b as number);
		await expect(cms.site().pages.restore(ids.a as number, ofB?.id as number)).rejects.toThrow("does not belong");
	});

	test("the editor's Revisions slide-over restores a snapshot", async () => {
		const { cms, db, ids } = await makeCms(fixture);
		const id = ids.about as number;
		const [created] = await cms.site().pages.revisions.list(id);
		await edit(cms, id, { heading: { en: "Second" } });
		const admin = createAdmin({
			prefix: "/admin",
			pages: cms.admin.pages,
			navigationGroups: cms.admin.navigationGroups,
			driver: kyselyDriver(db),
			hostRules: kyselyRules(db),
			user: () => ({ id: 1, name: "Admin" }),
			context: cms.admin.context,
		});
		const url = `/admin/pages/${id}/edit`;
		const view = await admin.handle(new Request(`http://x${url}`, { headers: { "x-inertia": "true" } }));
		expect(JSON.stringify(await view?.json())).toContain(`pageRevisionRestore_${created?.id}`);

		const res = await admin.handle(
			new Request(`http://x${url}/actions/pageRevisionRestore_${created?.id}`, {
				method: "POST",
				body: "{}",
				headers: { "content-type": "application/json", referer: `http://x${url}`, origin: "http://x" },
			}),
		);
		expect(res?.status).toBeLessThan(400);
		expect((await cms.site().pages.find(id))?.heading).toEqual({ en: "First" });
		expect((await cms.site().pages.revisions.list(id))[0]).toMatchObject({ kind: "restore", userId: 1 });
	});
});
