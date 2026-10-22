import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Channel, CmsConfig, MailMessage } from "../src";
import { cmsBlockImpls, cmsBlocks, implementBlocks } from "../src";
import { makeCms } from "./support";

type Made = Awaited<ReturnType<typeof makeCms>>;

async function contactAction(cms: Made["cms"]) {
	const result = await cms.loadPage(new Request("https://site.test/"));
	if (result.type !== "page") {
		throw new Error("no page");
	}
	const contact = Object.values(result.props.render.elements).find((element) => element.type === "ContactForm");
	return (contact?.props as { action: string }).action;
}

const post = (url: string, body: Record<string, unknown>, headers: Record<string, string> = {}) =>
	new Request(url, { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) });

const valid = { name: "Ada", email: "ada@example.test", message: "Hello" };

const rows = (db: Made["db"]) => db.selectFrom("tbtop_form_submissions").selectAll().execute();

function mailbox() {
	const sent: MailMessage[] = [];
	return { sent, transport: { send: async (message: MailMessage) => void sent.push(message) } };
}

/** ContactForm with other submit rules, so a test can post any field shape. */
const withRules = (rules: Record<string, string>) =>
	({
		blocks: implementBlocks(cmsBlocks, {
			...cmsBlockImpls,
			ContactForm: { ...cmsBlockImpls.ContactForm, submit: { rules: () => rules } },
		}),
	}) as Partial<CmsConfig>;

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
	const postTo = (url: string, body: unknown, referer: string) =>
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { referer: `http://x${referer}` } });
	const table = async () =>
		((await (await visit("/admin/form-submissions/tables/formSubmissions", { inertia: false })).json()) as { data: { data: Record<string, unknown>[] } }).data.data;
	return { ...made, visit, page, post: postTo, table };
}

describe("R3-forms-1: a cross-site post is refused", () => {
	test("a foreign Origin, a null Origin, a cross-site fetch or a foreign Referer is 403 and stores nothing", async () => {
		const { cms, db } = await makeCms();
		const action = await contactAction(cms);
		const form = (headers: Record<string, string>) =>
			new Request(action, {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded", ...headers },
				body: new URLSearchParams(valid).toString(),
			});
		expect((await cms.handle(form({ Origin: "https://evil.test", Referer: "https://evil.test/x" })))?.status).toBe(403);
		expect((await cms.handle(form({ Origin: "null" })))?.status).toBe(403);
		expect((await cms.handle(form({ "Sec-Fetch-Site": "cross-site" })))?.status).toBe(403);
		expect((await cms.handle(form({ Referer: "https://evil.test/x" })))?.status).toBe(403);
		expect((await cms.handle(post(action, valid, { Origin: "https://evil.test" })))?.status).toBe(403);
		expect(await rows(db)).toHaveLength(0);
	});

	test("a same-origin form post or fetch, and a client with neither header, still go through", async () => {
		const { cms, db } = await makeCms();
		const action = await contactAction(cms);
		const form = new Request(action, {
			method: "POST",
			headers: { "Content-Type": "application/x-www-form-urlencoded", Origin: "https://site.test", Referer: "https://site.test/", "Sec-Fetch-Site": "same-origin" },
			body: new URLSearchParams(valid).toString(),
		});
		expect((await cms.handle(form))?.status).toBe(303);
		expect((await cms.handle(post(action, valid, { Origin: "https://site.test" })))?.status).toBe(200);
		expect((await cms.handle(post(action, valid, { Referer: "https://site.test/contact" })))?.status).toBe(200);
		expect((await cms.handle(post(action, valid)))?.status).toBe(200);
		expect(await rows(db)).toHaveLength(4);
	});
});
