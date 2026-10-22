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

describe("R3-forms-3: inputs are trimmed and blanks become null, as Laravel's middleware does", () => {
	test("a padded email validates; padded text is stored trimmed", async () => {
		const { cms } = await makeCms();
		const action = await contactAction(cms);
		const response = await cms.handle(post(action, { name: "  Ada  ", email: " a@b.co ", message: " m \n" }));
		expect(response?.status).toBe(200);
		const [row] = await cms.site().submissions.query().select("id").execute();
		expect((await cms.site().submissions.findOrFail(Number(row?.id))).payload).toEqual({ name: "Ada", email: "a@b.co", message: "m" });
	});

	test("nested strings are trimmed, an empty one is null, a password keeps its spaces; form data alike", async () => {
		const { cms } = await makeCms(undefined, withRules({ name: "required|string", phone: "nullable|string", tags: "array", password: "required|string" }));
		const action = await contactAction(cms);
		await cms.handle(post(action, { name: " Ada ", phone: "  ", tags: [" a ", ""], password: " secret " }));
		const form = new URLSearchParams({ name: " Grace​", phone: "", password: " pw " });
		await cms.handle(new Request(action, { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: form.toString() }));
		const ids = (await cms.site().submissions.query().select("id").orderBy("id").execute()).map((row) => Number(row.id));
		const payloads = await Promise.all(ids.map(async (id) => (await cms.site().submissions.findOrFail(id)).payload));
		expect(payloads).toEqual([
			{ name: "Ada", phone: null, tags: ["a", null], password: " secret " },
			{ name: "Grace", phone: null, password: " pw " },
		]);
	});
});

describe("R3-forms-4: Telegram has a timeout and names the chat in its errors", () => {
	async function send(fetch: typeof globalThis.fetch) {
		const { cms, db } = await makeCms(undefined, { notifications: { telegram: { botToken: "T0KEN", fetch } } });
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["telegram"]);
		await settings.set("notifications.telegram.chat_ids", "42");
		await cms.handle(post(await contactAction(cms), valid));
		return (await rows(db))[0]?.error;
	}

	test("the request carries an abort signal; an unreachable API reads as PHP does", async () => {
		let signal: unknown;
		const error = await send((async (_url: string, init?: RequestInit) => {
			signal = init?.signal;
			throw new DOMException("The operation timed out.", "TimeoutError");
		}) as unknown as typeof fetch);
		expect(signal).toBeInstanceOf(AbortSignal);
		expect(error).toBe("Telegram delivery failed: chat 42: Could not reach Telegram.");
	});

	test("an API error, or a 200 that is not ok, carries the chat and the method", async () => {
		const failing = (async () => Response.json({ ok: false, description: "Bad Request: chat not found" }, { status: 400 })) as unknown as typeof fetch;
		expect(await send(failing)).toBe("Telegram delivery failed: chat 42: Telegram API error (sendMessage): Bad Request: chat not found");
		const notOk = (async () => Response.json({ ok: false })) as unknown as typeof fetch;
		expect(await send(notOk)).toBe("Telegram delivery failed: chat 42: Telegram API error (sendMessage): HTTP 200");
	});
});
