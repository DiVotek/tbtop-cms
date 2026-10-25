import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { definePageType, type CmsConfig } from "../src";
import { makeCms } from "./support";

const solution = definePageType({ key: "solution", base: "page", form: (s) => [s.text("price").label("Price").required(), s.text("note").label("Note")] });
const offer = definePageType({ key: "offer", base: "page", form: (s) => [s.text("price").label("Offer price"), s.text("deadline").label("Deadline").required()] });
const landing = definePageType({ key: "landing", form: (s) => [s.text("price").label("Price"), s.text("heroLabel").label("Hero label")] });

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
	const visit = (url: string, init: RequestInit = {}) => {
		const headers = new Headers(init.headers);
		headers.set("x-inertia", "true");
		return admin.handle(new Request(`http://x${url}`, { ...init, headers })) as Promise<Response>;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const record = async (id: number) => ((await page(`/admin/pages/${id}/edit`)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
	const save = async (id: number, changes: Record<string, unknown>) => {
		const url = `/admin/pages/${id}/edit`;
		return visit(`${url}/forms/pageEditor`, {
			method: "POST",
			body: JSON.stringify({ ...(await record(id)), ...changes }),
			headers: { "content-type": "application/json", referer: `http://x${url}` },
		});
	};
	return { ...made, page, record, save };
}

describe("the page editor's type fields (PageEditTypeFields)", () => {
	test("a page type's form fields show in the editor, filled from page.fields, and save back", async () => {
		const { cms, page, record, save } = await panel({ pageTypes: [landing] });
		const created = await cms.site().pages.create({ type: "landing", name: { en: "Promo" }, fields: { price: "100", heroLabel: "Hi", legacy: "kept" } });
		const props = JSON.stringify((await page(`/admin/pages/${created.id}/edit`)).props);
		expect(props).toContain('"name":"field_price"');
		expect(props).toContain('"name":"field_heroLabel"');
		expect(await record(created.id)).toMatchObject({ field_price: "100", field_heroLabel: "Hi" });

		expect((await save(created.id, { field_price: "120" })).status).toBe(303);
		expect((await cms.site().pages.find(created.id))?.fields).toEqual({ price: "120", heroLabel: "Hi", legacy: "kept" });
	});

	test("a subtype's section shows only while it is picked; its fields win over the base's, its required holds only then", async () => {
		const { cms, page, save } = await panel({ pageTypes: [solution, offer] });
		const created = await cms.site().pages.create({ type: "page", subtype: "solution", name: { en: "CRM" }, fields: { price: "10" } });
		const props = JSON.stringify((await page(`/admin/pages/${created.id}/edit`)).props);
		expect(props).toContain('"name":"field_solution__price"');
		expect(props).toContain('"name":"field_offer__deadline"');

		// The offer's required deadline does not block a solution save.
		expect((await save(created.id, { field_solution__price: "15", field_offer__deadline: "" })).status).toBe(303);
		expect((await cms.site().pages.find(created.id))?.fields).toEqual({ price: "15" });

		// Switching to the offer subtype saves its fields under the bare names.
		await save(created.id, { subtype: "offer", field_offer__price: "9", field_offer__deadline: "Friday" });
		const switched = await cms.site().pages.find(created.id);
		expect([switched?.subtype, switched?.fields]).toEqual(["offer", { price: "9", deadline: "Friday" }]);
	});
});

describe("the page editor's layout (PageEditPage)", () => {
	test("the page's name is the title and its path the subtitle; status bar, sidebar cards and footer are there", async () => {
		const { page, ids } = await panel();
		const res = await page(`/admin/pages/${ids.about}/edit`);
		expect([res.props.title, res.props.subtitle]).toEqual(["About us", "/about-us"]);
		expect(JSON.stringify(res.props.breadcrumbs)).toContain('"label":"About us"');
		const tree = JSON.stringify(res.props.structure);
		for (const title of ["Publication", "Structure", "Ordering", "Image"]) {
			expect(tree).toContain(`"title":"${title}","variant":"card"`);
		}
		for (const name of ["saveTop", "save", "cancel", "pageEditView", "pageEditDelete"]) {
			expect(tree).toContain(`"name":"${name}"`);
		}
		expect(tree).toContain('"label":"Published"');
	});

	test("a page with children or a system page has no delete button", async () => {
		const { page, ids } = await panel();
		expect(JSON.stringify((await page(`/admin/pages/${ids.blog}/edit`)).props.structure)).not.toContain("pageEditDelete");
		expect(JSON.stringify((await page(`/admin/pages/${ids.home}/edit`)).props.structure)).not.toContain("pageEditDelete");
	});
});
