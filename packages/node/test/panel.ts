import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Fixture } from "../src";
import { makeCms } from "./support";

/** A CMS with an admin panel over it, signed in as user 1; `post` sends JSON with a same-origin referer. */
export async function panel(fixture?: Fixture | null, config: Parameters<typeof makeCms>[1] = {}) {
	const made = await makeCms(fixture, config);
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
	const visit = async (url: string, init: RequestInit & { inertia?: boolean } = {}) => {
		const headers = new Headers(init.headers);
		if (init.inertia !== false) {
			headers.set("x-inertia", "true");
		}
		const res = await admin.handle(new Request(`http://x${url}`, { ...init, headers }));
		if (res === null) {
			throw new TypeError(`${url} fell through`);
		}
		return res;
	};
	const page = async (url: string) => (await (await visit(url)).json()) as InertiaPage;
	const post = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const rows = async (table: string, url: string, tab = "") => {
		const res = await visit(`${url}/tables/${table}${tab === "" ? "" : `?tab=${tab}`}`, { inertia: false });
		return ((await res.json()) as { data: { data: Record<string, unknown>[] } }).data.data;
	};
	return { ...made, admin, visit, page, post, rows };
}
