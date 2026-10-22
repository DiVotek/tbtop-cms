import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, type CmsConfig, implementBlocks, resolveForm } from "../src";
import type { ResolveContext } from "../src/blocks/context";
import { get, makeCms } from "./support";

type Row = { id: string; kind: string; active?: boolean; sort?: number; shared?: number; data?: Record<string, unknown> };

async function panel(config: Partial<CmsConfig> = {}) {
	const made = await makeCms(undefined, config);
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
	const visit = async (url: string, init: RequestInit = {}) => {
		const headers = new Headers(init.headers);
		headers.set("x-inertia", "true");
		const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		return res;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const record = async (url: string, form: string) => ((await page(url)).props.data as Record<string, Record<string, unknown>>)[form] ?? {};
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { referer: `http://x${referer}` } });
	const action = (url: string, name: string, form: Record<string, unknown>) => post(`${url}/actions/${name}`, { payload: { form } }, url);
	const rows = async (id: number) => ((await cms.site().pages.find(id))?.content ?? []) as Row[];
	const setRows = async (id: number, content: Row[]) => {
		await db.updateTable("tbtop_pages").set({ content: JSON.stringify(content) }).where("id", "=", id).execute();
	};
	return { ...made, admin, visit, page, record, post, action, rows, setRows };
}

/** The demo kinds plus Banner, a layout-only kind with a required field. */
const withBanner = () =>
	implementBlocks(
		{ ...cmsBlocks, Banner: { props: z.object({ text: z.string().nullable() }), placements: ["layout"] } },
		{ ...cmsBlockImpls, Banner: { form: (s) => [s.text("text").label("Text").required()] } },
	);

const effects = async (res: Response) => ((await res.json()) as { effects: { kind: string; message?: string; level?: string }[] }).effects;
const json = (value: unknown) => JSON.stringify(value);

describe("R3-blocks-1: a new block is added inactive", () => {
	test("Add block on a published page stores an inactive row the site does not render", async () => {
		const { cms, record, action, rows, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		const res = await action(url, "blockAdd", { ...(await record(url, "pageEditor")), new_block_kind: "Hero" });
		expect(res.status).toBe(200);
		const stored = await rows(ids.about as number);
		expect(stored.map((row) => [row.kind, row.active])).toEqual([["Hero", false]]);
		const result = await cms.loadPage(get("/about-us"));
		if (result.type !== "page") throw new Error(result.type);
		expect(json(result.props)).not.toContain('"Hero"');
	});

	test("Add block in the header layout stores an inactive row", async () => {
		const { cms, action } = await panel({ blocks: withBanner() });
		const res = await action("/admin/layout/header", "blockAdd", { new_block_kind: "Banner" });
		expect(res.status).toBe(200);
		const header = (await cms.site().layouts.content()).header as Row[];
		expect(header.map((row) => [row.kind, row.active])).toEqual([["Banner", false]]);
	});
});

describe("R3-blocks-2: structural actions keep the stored status and validate published pages", () => {
	test("Add block on a draft page does not publish it, even with status flipped in the form", async () => {
		const { cms, record, action, ids } = await panel();
		const url = `/admin/pages/${ids.draft}/edit`;
		const res = await action(url, "blockAdd", { ...(await record(url, "pageEditor")), new_block_kind: "Hero", status: "published" });
		expect(res.status).toBe(200);
		const page = await cms.site().pages.find(ids.draft as number);
		expect(page?.status).toBe("draft");
		expect(page?.content?.length).toBe(1);
	});

	test("Remove block on a draft page keeps the stored status", async () => {
		const { cms, record, action, setRows, ids } = await panel();
		await setRows(ids.draft as number, [{ id: "aaaa1111", kind: "Hero", active: true, sort: 0, data: { title: { en: "T" } } }]);
		const url = `/admin/pages/${ids.draft}/edit`;
		await action(url, "blockRemove_aaaa1111", { ...(await record(url, "pageEditor")), status: "published" });
		const page = await cms.site().pages.find(ids.draft as number);
		expect([page?.status, page?.content]).toEqual(["draft", []]);
	});

	test("Add block on a published page runs the form rules first", async () => {
		const { record, action, rows, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		const res = await action(url, "blockAdd", { ...(await record(url, "pageEditor")), name: { en: "" }, new_block_kind: "Hero" });
		expect(res.status).toBe(422);
		expect(await rows(ids.about as number)).toEqual([]);
	});

	test("Add block in the layout editor runs the form rules", async () => {
		const { cms, action } = await panel({ blocks: withBanner() });
		await cms.site().layouts.save("header", [{ id: "hhhh1111", kind: "Banner", active: true, sort: 0, data: { text: "Top" } }]);
		const res = await action("/admin/layout/header", "blockAdd", {
			block_hhhh1111_active: true,
			block_hhhh1111_sort: 0,
			block_hhhh1111_text: "",
			new_block_kind: "Banner",
		});
		expect(res.status).toBe(422);
		expect(((await cms.site().layouts.content()).header as Row[]).length).toBe(1);
	});
});

describe("R3-blocks-3: an inactive block's required fields do not block Save", () => {
	test("the page editor saves with an empty inactive Hero, and still requires an active one's title", async () => {
		const { cms, record, post, setRows, ids } = await panel();
		await setRows(ids.about as number, [{ id: "abc12345", kind: "Hero", active: false, sort: 0, data: {} }]);
		const url = `/admin/pages/${ids.about}/edit`;
		const form = await record(url, "pageEditor");
		await post(`${url}/forms/pageEditor`, { ...form, name: { en: "Renamed", uk: "Про нас" } }, url);
		expect(((await cms.site().pages.find(ids.about as number))?.name as Record<string, string>).en).toBe("Renamed");

		const fresh = await record(url, "pageEditor");
		await post(`${url}/forms/pageEditor`, { ...fresh, name: { en: "Again", uk: "Про нас" }, block_abc12345_active: true }, url);
		expect(((await cms.site().pages.find(ids.about as number))?.name as Record<string, string>).en).toBe("Renamed");
	});

	test("the shared block editor still requires its fields", async () => {
		const { cms, post } = await panel();
		const id = await cms.site().sharedBlocks.create({ name: "Top hero", kind: "Hero", data: { title: { en: "Hi" } } });
		const url = `/admin/shared-blocks/${id}/edit`;
		await post(`${url}/forms/sharedBlock`, { lock_version: 0, shared_name: "Renamed", block_shared_title: { en: "" } }, url);
		expect((await cms.site().sharedBlocks.find(id))?.name).toBe("Top hero");
	});
});

describe("R3-blocks-5: SEO fields are stored as locale maps", () => {
	test("saving the editor unchanged stores maps and a blank og_image, and the editor opens again", async () => {
		const { cms, page, record, post, ids } = await panel();
		const url = `/admin/pages/${ids.about}/edit`;
		const form = await record(url, "pageEditor");
		const res = await post(`${url}/forms/pageEditor`, { ...form, seo_title: { en: "Ours", uk: null } }, url);
		expect(res.status).toBe(303);
		const seo = (await cms.site().pages.find(ids.about as number))?.seo as Record<string, unknown>;
		expect(seo.title).toEqual({ en: "Ours", uk: "" });
		for (const key of ["description", "og_title", "og_description", "canonical"]) {
			expect([key, seo[key]]).toEqual([key, {}]);
		}
		expect(seo.og_image).toBe("");
		expect((await page(url)).component).toBe("admin/page");
		expect((await record(url, "pageEditor")).og_image).toBeNull();
	});
});
