import { describe, expect, test } from "bun:test";
import { runWithContext } from "@tbtop/admin";
import { runCommand } from "../src/cli/run";
import { panel } from "./panel";
import { makeCms } from "./support";

const en = [{ locale: "en", name: "English", default: true }];
const fixture = { languages: en, pages: [{ key: "home", system: true, name: { en: "Home" } }] };

const asAdmin = <T>(run: () => Promise<T>) => runWithContext({ user: { id: 7, name: "Editor" } }, run);

describe("audit log", () => {
	test("writes outside a signed-in admin request record nothing", async () => {
		const { cms } = await makeCms(fixture);
		const site = cms.site();
		const page = await site.pages.create({ type: "page", name: { en: "About" } });
		await site.pages.update(page.id, { name: { en: "About us" } });
		await site.layouts.save("header", []);

		expect(await cms.tasks.audit()).toEqual([]);
	});

	test("a page's create, edits, revision restore and delete record who changed which fields", async () => {
		const { cms } = await makeCms(fixture);
		const site = cms.site();
		const page = await asAdmin(() => site.pages.create({ type: "page", name: { en: "About" }, content: [{ id: "a", kind: "hero", data: {} }] }));
		await asAdmin(() => site.pages.update(page.id, { name: { en: "About us" } }));
		await asAdmin(() => site.pages.update(page.id, { name: { en: "About us" } }));
		await asAdmin(() => site.pages.update(page.id, { content: [] }));
		const [, first] = await site.pages.revisions.list(page.id).then((list) => list.slice(-2));
		await asAdmin(() => site.pages.restore(page.id, first?.id ?? 0));
		await asAdmin(() => site.pages.delete(page.id));

		const entries = (await cms.tasks.audit({ subjectType: "page", subjectId: page.id })).reverse();
		expect(entries.map((entry) => [entry.event, entry.changedFields])).toEqual([
			["created", ["type", "status", "name", "content", "is_indexable", "is_system", "sort_order"]],
			["updated", ["name"]],
			["updated", ["content"]],
			["revision_restored", ["name", "content"]],
			["deleted", []],
		]);
		const [, renamed, emptied, restored] = entries;
		expect(renamed).toMatchObject({ causerId: 7, label: "About us", changes: { old: { name: { en: "About" } }, attributes: { name: { en: "About us" } } } });
		// content is a marker: the field is named, its blocks are not copied.
		expect(emptied?.changes).toEqual({ old: {}, attributes: {} });
		expect(restored?.context).toEqual({ revision_id: first?.id });
	});

	test("layouts keep a block summary; redirects, languages and shared blocks are tracked", async () => {
		const { cms } = await makeCms(fixture);
		const site = cms.site();
		await asAdmin(() => site.layouts.save("header", [{ id: "n1", kind: "hero", data: { title: "Hi" } }]));
		await asAdmin(() => site.layouts.save("header", [{ id: "n1", kind: "hero", data: { title: "Hello" } }, { id: "n2", kind: "faq", data: {} }]));
		const redirect = await asAdmin(() => site.redirects.create({ fromPath: "old", toPath: "/" }));
		await asAdmin(() => site.redirects.delete(redirect));
		await asAdmin(() => site.languages.create({ locale: "de", isAdminActive: true }));
		const block = await asAdmin(() => site.sharedBlocks.create({ name: "Promo", kind: "hero", data: {} }));
		await asAdmin(() => site.sharedBlocks.update(block, { name: "Promo 2" }));

		const entries = (await cms.tasks.audit()).reverse();
		expect(entries.map((entry) => `${entry.event} ${entry.subjectType} ${entry.label}`)).toEqual([
			"created layout header",
			"updated layout header",
			"created redirect old",
			"deleted redirect old",
			"created language Deutsch",
			"created shared_block Promo",
			"updated shared_block Promo 2",
		]);
		expect(entries[1]?.changes).toEqual({
			old: { content: [{ id: "n1", kind: "hero" }] },
			attributes: { content: [{ id: "n1", kind: "hero" }, { id: "n2", kind: "faq" }] },
		});
	});

	test("an admin panel action is recorded as the signed-in user", async () => {
		const { cms, post, rows } = await panel(fixture);
		await cms.site().redirects.insert({ fromPath: "old", toPath: "/" });
		const url = "/admin/redirects";
		const [row] = await rows("redirects", url);
		const res = await post(`${url}/actions/delete`, { payload: { row } }, url);
		expect(res.status).toBeLessThan(400);

		expect((await cms.tasks.audit()).map((entry) => [entry.event, entry.subjectType, entry.causerId])).toEqual([["deleted", "redirect", 1]]);
	});

	test("prune drops old entries; the CLI lists and prunes", async () => {
		const { cms, db } = await makeCms(fixture);
		await asAdmin(() => cms.site().layouts.save("footer", []));
		await asAdmin(() => cms.site().layouts.save("header", []));
		const footer = (await cms.tasks.audit()).find((entry) => entry.label === "footer");
		await db.updateTable("activity_log").set({ created_at: "2020-01-01 00:00:00" }).where("id", "=", footer?.id ?? 0).execute();

		const out: string[] = [];
		const io = { out: (line: string) => out.push(line), err: (line: string) => out.push(line) };
		expect(await runCommand(cms, ["audit", "--type", "layout"], io)).toBe(0);
		expect(out.filter((line) => line.includes("created layout"))).toHaveLength(2);
		expect(out.at(-1)).toContain("2 entries.");

		expect(await runCommand(cms, ["audit:prune"], io)).toBe(0);
		expect(out.at(-1)).toBe("Deleted 1 audit entries.");
		expect((await cms.tasks.audit()).map((entry) => entry.label)).toEqual(["header"]);
	});
});
