import { describe, expect, test } from "bun:test";
import { createAdmin, type InertiaPage } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import type { Channel, CmsConfig, MailMessage } from "../src";
import { z } from "zod";
import { cmsBlockImpls, cmsBlocks, implementBlocks, type PayloadSchema } from "../src";
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
	new Request(new URL(url, "https://site.test"), { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) });

const valid = { name: "Ada", email: "ada@example.test", message: "Hello" };

const rows = (db: Made["db"]) => db.selectFrom("tbtop_form_submissions").selectAll().execute();

function mailbox() {
	const sent: MailMessage[] = [];
	return { sent, transport: { send: async (message: MailMessage) => void sent.push(message) } };
}

/** ContactForm with another submit schema, so a test can post any field shape. */
const withSchema = (schema: PayloadSchema) =>
	({
		blocks: implementBlocks(cmsBlocks, {
			...cmsBlockImpls,
			ContactForm: { ...cmsBlockImpls.ContactForm, submit: { schema } },
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
		visit(url, { method: "POST", body: JSON.stringify(body), headers: { "content-type": "application/json", referer: `http://x${referer}` } });
	const table = async () =>
		((await (await visit("/admin/form-submissions/tables/formSubmissions", { inertia: false })).json()) as { data: { data: Record<string, unknown>[] } }).data.data;
	return { ...made, visit, page, post: postTo, table };
}

describe("R3-forms-1: a cross-site post is refused", () => {
	test("a foreign Origin, a null Origin, a cross-site fetch or a foreign Referer is 403 and stores nothing", async () => {
		const { cms, db } = await makeCms();
		const action = await contactAction(cms);
		const form = (headers: Record<string, string>) =>
			new Request(new URL(action, "https://site.test"), {
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
		const form = new Request(new URL(action, "https://site.test"), {
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

describe("R4-fixes-4: behind a TLS-terminating proxy", () => {
	test("without a configured url, an https Origin of the same host passes an http request URL", async () => {
		const { cms, db } = await makeCms(undefined, { url: undefined });
		const path = new URL(await contactAction(cms), "https://site.test").pathname;
		const proxied = (origin: string) => post(`http://site.test${path}`, valid, { Origin: origin });
		expect((await cms.handle(proxied("https://site.test")))?.status).toBe(200);
		expect((await cms.handle(proxied("https://evil.test")))?.status).toBe(403);
		expect(await rows(db)).toHaveLength(1);
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
		const { cms } = await makeCms(undefined, withSchema(z.object({ name: z.string(), phone: z.string().nullable(), tags: z.array(z.string().nullable()).optional(), password: z.string() })));
		const action = await contactAction(cms);
		await cms.handle(post(action, { name: " Ada ", phone: "  ", tags: [" a ", ""], password: " secret " }));
		const form = new URLSearchParams({ name: " Grace​", phone: "", password: " pw " });
		await cms.handle(new Request(new URL(action, "https://site.test"), { method: "POST", headers: { "Content-Type": "application/x-www-form-urlencoded" }, body: form.toString() }));
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

describe("R3-forms-7: Blade overrides in the shared table", () => {
	const subject = "tpl__form_submission__html__en__subject";
	const body = "tpl__form_submission__html__en__body";
	const blade = "@foreach($fields as $k => $v) {{ $k }} @endforeach";

	test("an untouched Blade row from PHP does not block the save and stays as it was", async () => {
		const { cms, db, page, post: save } = await panel();
		const now = "2026-10-22 00:00:00";
		await db
			.insertInto("tbtop_notification_templates")
			.values({ type: "form.submission", format: "html", locale: "en", subject: "Lead {{ $name }}", body: blade, created_at: now, updated_at: now })
			.execute();
		const url = "/admin/notification-settings";
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).notification_settings ?? {};
		await save(`${url}/forms/notification_settings`, { ...record, mail_from_name: "Shop" }, url);
		expect(await cms.site().settings.get("mail.from_name")).toBe("Shop");
		const stored = await db.selectFrom("tbtop_notification_templates").select(["subject", "body"]).execute();
		expect(stored).toEqual([{ subject: "Lead {{ $name }}", body: blade }]);
	});

	test("new Blade text is refused, in the body or the subject", async () => {
		const { cms, db, page, post: save } = await panel();
		const url = "/admin/notification-settings";
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).notification_settings ?? {};
		await save(`${url}/forms/notification_settings`, { ...record, mail_from_name: "A", [body]: "@php system('id'); @endphp" }, url);
		await save(`${url}/forms/notification_settings`, { ...record, mail_from_name: "B", [subject]: "{!! $x !!}", [body]: "<p>{{ pageName }}</p>" }, url);
		expect(await cms.site().settings.get("mail.from_name")).toBeNull();
		expect(await db.selectFrom("tbtop_notification_templates").select("id").execute()).toHaveLength(0);
	});

	test("anything Blade or PHP would run is refused, not only the common directives (R4-fixes-1)", async () => {
		const { db, page, post: save } = await panel();
		const url = "/admin/notification-settings";
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).notification_settings ?? {};
		for (const code of ["<?php system('id'); ?>", "<?= 1 ?>", "@json(system('id'))", "@unless(true) x @endunless", "@include('x')", "{{ system('id') }}"]) {
			await save(`${url}/forms/notification_settings`, { ...record, [body]: `<p>${code}</p>` }, url);
		}
		expect(await db.selectFrom("tbtop_notification_templates").select("id").execute()).toHaveLength(0);
		await save(`${url}/forms/notification_settings`, { ...record, [body]: "<p>{{ pageName }} — mail info@example.test</p>" }, url);
		expect(await db.selectFrom("tbtop_notification_templates").select("id").execute()).toHaveLength(1);
	});
});

describe("R3-forms-9: field text matches PHP", () => {
	test("booleans read 1 and empty, false is no lead, and truncation keeps whole characters", async () => {
		const mail = mailbox();
		const { cms, table } = await panel({
			...withSchema(z.object({ newsletter: z.boolean().optional(), note: z.string().nullable().optional(), consent: z.literal(true) })),
			notifications: { mail: mail.transport },
		});
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["mail"]);
		await settings.set("notifications.mail.recipients", "team@example.test");
		const action = await contactAction(cms);
		await cms.handle(post(action, { consent: true, newsletter: false }));
		expect(mail.sent[0]?.html).toContain("<p><strong>consent:</strong> 1</p>");
		expect(mail.sent[0]?.html).toContain("<p><strong>newsletter:</strong> </p>");
		await cms.handle(post(action, { newsletter: false, consent: true, note: `${"a".repeat(58)}😀tail` }));
		const leads = (await table()).map((row) => row.payload).sort();
		expect(leads).toEqual(["1", `${"a".repeat(58)}😀…`]);
	});
});

describe("R3-forms-10: the inbox page label falls back like PHP", () => {
	test("a name in another locale, then a title, then the slug, then Page #id", async () => {
		const { cms, table } = await panel();
		const site = cms.site();
		const pages = [
			await site.pages.create({ type: "page", name: { uk: "Контакти" }, status: "published" }),
			await site.pages.create({ type: "page", name: { en: " " }, title: { uk: "Ціни" }, status: "published" }),
			await site.pages.create({ type: "page", status: "published" }, { slugs: { en: "bare" } }),
		];
		for (const [index, page] of pages.entries()) {
			await site.submissions.create({ pageId: page.id, blockId: "fx1", kind: "ContactForm", locale: "en", payload: { name: `P${index}` } });
		}
		const labels = Object.fromEntries((await table()).map((row) => [row.payload, row.page_id]));
		expect(labels).toEqual({ P0: "Контакти", P1: "Ціни", P2: "bare" });
	});
});

async function mailSite() {
	const mail = mailbox();
	const { cms, db } = await makeCms(undefined, { notifications: { mail: mail.transport } });
	const settings = cms.site().settings;
	await settings.set("notifications.form.submission.channels", ["mail"]);
	await settings.set("notifications.mail.recipients", "team@example.test");
	await settings.set("mail.from_address", "shop@example.test");
	return { cms, db, settings, sent: mail.sent };
}

describe("R3-forms-11: the From display name", () => {
	test("a name with specials is quoted and escaped; a plain one is not", async () => {
		const { cms, settings, sent } = await mailSite();
		await settings.set("mail.from_name", 'Smith, Jones & "Co"');
		await cms.handle(post(await contactAction(cms), valid));
		expect(sent[0]?.from).toBe('"Smith, Jones & \\"Co\\"" <shop@example.test>');
		await settings.set("mail.from_name", "Acme Shop");
		await cms.handle(post(await contactAction(cms), valid));
		expect(sent[1]?.from).toBe("Acme Shop <shop@example.test>");
	});
});

describe("R3-forms-12: a rendered subject", () => {
	test("is one line, whatever the visitor typed", async () => {
		const { cms, db, sent } = await mailSite();
		const now = "2026-10-22 00:00:00";
		await db
			.insertInto("tbtop_notification_templates")
			.values({ type: "form.submission", format: "html", locale: "en", subject: "Lead: {{ leadIdentity }}", body: "<p>x</p>", created_at: now, updated_at: now })
			.execute();
		await cms.handle(post(await contactAction(cms), { ...valid, name: "Ada\r\nBcc: x@evil.test" }));
		expect(sent[0]?.subject).toBe("Lead: Ada Bcc: x@evil.test · ada@example.test");
	});
});

describe("R3-forms-13: the channels checklist offers only channels the type can render", () => {
	test("a text-format host channel is not offered on form.submission", async () => {
		const sms: Channel = { key: "sms", label: "SMS", format: "text", supports: () => true, send: async () => {} };
		const mail = mailbox();
		const { page } = await panel({ notifications: { mail: mail.transport, channels: [sms] } });
		const props = JSON.stringify((await page("/admin/notification-settings")).props);
		expect(props).toContain('"value":"mail"');
		expect(props).not.toContain('"value":"sms"');
	});
});

describe("R4-fixes-2: the notification settings save trims like the other admin forms", () => {
	test("a padded sender name is stored trimmed", async () => {
		const { cms, page, post: save } = await panel();
		const url = "/admin/notification-settings";
		const record = ((await page(url)).props.data as Record<string, Record<string, unknown>>).notification_settings ?? {};
		await save(`${url}/forms/notification_settings`, { ...record, mail_from_name: "  Shop  " }, url);
		expect(await cms.site().settings.get("mail.from_name")).toBe("Shop");
	});
});
