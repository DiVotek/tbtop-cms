import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { CmsConfig } from "../src";
import { makeCms } from "./support";

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
	/** The notice a submit flashed, read off the next page view. */
	const flashed = async () => JSON.stringify(((await page("/admin/pages")) as InertiaPage & { flash?: unknown }).flash ?? null);
	const editor = async (id: number) => ((await page(`/admin/pages/${id}/edit`)).props.data as Record<string, Record<string, unknown>>).pageEditor ?? {};
	const save = async (id: number, changes: Record<string, unknown>) => {
		const url = `/admin/pages/${id}/edit`;
		return post(`${url}/forms/pageEditor`, { ...(await editor(id)), ...changes }, url);
	};
	return { ...made, admin, visit, page, post, flashed, editor, save };
}


describe("R2-content-7: unparsable dates", () => {
	test("an unparsable date is saved as empty instead of a 500", async () => {
		const { cms, save, ids } = await panel();
		const about = ids.about as number;
		const res = await save(about, { published_at: "not a date", unpublish_at: "2027-01-01T00:00:00Z" });
		expect(res.status).toBe(303);
		const page = await cms.site().pages.find(about);
		expect(page?.publishedAt).toBeNull();
		expect(page?.unpublishAt?.toISOString()).toBe("2027-01-01T00:00:00.000Z");
	});
});
