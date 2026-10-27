import { describe, expect, test } from "bun:test";
import { memoryNotifications } from "@tbtop/admin";
import type { CmsConfig, MailMessage } from "../src";
import { cmsBlocks, cmsBlockImpls, implementBlocks } from "../src";
import { z } from "zod";
import { makeCms } from "./support";

/** The home page's ContactForm action, as the frontend props carry it. */
async function contactAction(cms: Awaited<ReturnType<typeof makeCms>>["cms"], path = "/", origin = "https://site.test") {
	const result = await cms.loadPage(new Request(`${origin}${path}`));
	if (result.type !== "page") {
		throw new Error("no page");
	}
	const contact = Object.values(result.props.render.elements).find((element) => element.type === "ContactForm");
	return contact?.props as { action: string; honeypotField?: string };
}

const post = (url: string, body: Record<string, unknown>, headers: Record<string, string> = {}) =>
	new Request(new URL(url, "https://site.test"), { method: "POST", headers: { "Content-Type": "application/json", ...headers }, body: JSON.stringify(body) });

const valid = { name: "Ada", email: "ada@example.test", message: "Hello\nthere" };

function mailbox() {
	const sent: MailMessage[] = [];
	return { sent, transport: { send: async (message: MailMessage) => void sent.push(message) } };
}

function telegram() {
	const calls: { url: string; body: Record<string, unknown> }[] = [];
	const fetch = (async (url: string, init?: RequestInit) => {
		calls.push({ url, body: JSON.parse(String(init?.body)) });
		return Response.json({ ok: true });
	}) as unknown as typeof globalThis.fetch;
	return { calls, fetch };
}

async function rows(db: Awaited<ReturnType<typeof makeCms>>["db"]) {
	return db.selectFrom("tbtop_form_submissions").selectAll().execute();
}

describe("form submissions", () => {
	test("the form props carry a root-relative submit path and the honeypot field", async () => {
		const { cms } = await makeCms();
		const props = await contactAction(cms);
		expect(props.action).toMatch(/^\/_cms\/forms\/en\/\d+\/fx\d+$/);
		expect(props.honeypotField).toBe("website");
	});

	test("a page served on another origin than config.url posts its form to itself", async () => {
		const { cms } = await makeCms(undefined, { url: "https://example.com" });
		const props = await contactAction(cms, "/", "http://localhost:3310");
		expect(props.action.startsWith("/_cms/forms/")).toBe(true);
		const response = await cms.handle(new Request(new URL(props.action, "http://localhost:3310"), {
			method: "POST",
			headers: { "Content-Type": "application/json", Accept: "application/json", Origin: "http://localhost:3310" },
			body: JSON.stringify(valid),
		}));
		expect(response?.status).toBe(200);
		expect(await response?.json()).toEqual({ ok: true });
	});

	test("a valid submit is stored in field order and handled; only validated keys are kept", async () => {
		const { cms, db } = await makeCms();
		const { action } = await contactAction(cms);
		const response = await cms.handle(post(action, { message: valid.message, extra: "dropped", email: valid.email, name: valid.name }));
		expect(response?.status).toBe(200);
		expect(await response?.json()).toEqual({ ok: true });
		const [row] = await rows(db);
		expect(row).toMatchObject({ kind: "ContactForm", locale: "en", status: "handled", lead_status: "new", error: null });
		expect(row?.handled_at).not.toBeNull();
		const stored = await cms.site().submissions.findOrFail(Number(row?.id));
		expect(Object.keys(stored.payload)).toEqual(["name", "email", "message"]);
	});

	test("invalid input is 422 with the field errors and stores nothing", async () => {
		const { cms, db } = await makeCms();
		const { action } = await contactAction(cms);
		const response = await cms.handle(post(action, { name: "Ada", email: "not-an-email" }));
		expect(response?.status).toBe(422);
		const body = (await response?.json()) as { errors: Record<string, string[]> };
		expect(Object.keys(body.errors).sort()).toEqual(["email", "message"]);
		expect(await rows(db)).toHaveLength(0);
	});

	test("field errors speak the page's language; the host's strings override the package's", async () => {
		const { cms } = await makeCms(undefined, { strings: { uk: { "validation.attributes.message": "текст заявки" } } });
		const errors = async (path: string) => {
			const { action } = await contactAction(cms, path);
			return ((await (await cms.handle(post(action, { name: "", email: "x" })))?.json()) as { errors: Record<string, string[]> }).errors;
		};
		expect(await errors("/uk")).toEqual({
			name: ["Поле ім’я є обов’язковим."],
			email: ["Поле електронна пошта повинно містити дійсну адресу електронної пошти."],
			message: ["Поле текст заявки є обов’язковим."],
		});
		expect((await errors("/")).name).toEqual(["The name field is required."]);
	});

	test("a filled honeypot looks like success and stores nothing", async () => {
		const { cms, db } = await makeCms();
		const { action } = await contactAction(cms);
		const response = await cms.handle(post(action, { ...valid, website: "spam.example" }));
		expect(response?.status).toBe(200);
		expect(await rows(db)).toHaveLength(0);
	});

	test("an unknown block, a draft page or a GET is not a form post", async () => {
		const { cms, ids } = await makeCms();
		expect((await cms.handle(post(`https://site.test/_cms/forms/en/${ids.home}/nope`, valid)))?.status).toBe(404);
		expect((await cms.handle(post(`https://site.test/_cms/forms/en/${ids.draft}/fx000002`, valid)))?.status).toBe(404);
		expect(await cms.handle(new Request(`https://site.test/_cms/forms/en/${ids.home}/fx000002`))).toBeNull();
	});

	test("a plain HTML form gets a 303 back to the page", async () => {
		const { cms } = await makeCms();
		const { action } = await contactAction(cms);
		const response = await cms.handle(
			new Request(new URL(action, "https://site.test"), {
				method: "POST",
				headers: { "Content-Type": "application/x-www-form-urlencoded", Referer: "https://site.test/?a=1" },
				body: new URLSearchParams(valid).toString(),
			}),
		);
		expect(response?.status).toBe(303);
		const location = new URL(response?.headers.get("location") ?? "");
		expect(location.searchParams.get("a")).toBe("1");
		expect(location.searchParams.get("cms_form_status")).toBe("sent");
	});

	test("mail and Telegram go out per settings and are logged; the submission is handled", async () => {
		const mail = mailbox();
		const bot = telegram();
		const { cms, db } = await makeCms(undefined, {
			notifications: { mail: mail.transport, telegram: { botToken: "T0KEN", fetch: bot.fetch } },
		});
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["mail", "telegram"]);
		await settings.set("notifications.mail.recipients", "team@example.test; bad-address");
		await settings.set("notifications.telegram.chat_ids", "-1001 # Sales\n42");
		await settings.set("mail.from_address", "site@example.test");
		const { action } = await contactAction(cms);
		expect((await cms.handle(post(action, valid)))?.status).toBe(200);

		expect(mail.sent).toHaveLength(1);
		expect(mail.sent[0]).toMatchObject({ to: "team@example.test", from: "site@example.test", subject: "New form submission" });
		expect(mail.sent[0]?.html).toContain("<p><strong>Message:</strong> Hello<br>\nthere</p>");
		expect(mail.sent[0]?.html).toMatch(/href="https:\/\/site\.test\/admin\/form-submissions\/\d+"/);
		expect(bot.calls.map((call) => call.body.chat_id)).toEqual(["-1001", "42"]);
		expect(bot.calls[0]?.url).toBe("https://api.telegram.org/botT0KEN/sendMessage");
		expect(String(bot.calls[0]?.body.text)).toContain("<b>New form submission</b>\nAda\nada@example.test\nPage: Home");

		const [row] = await rows(db);
		expect(row).toMatchObject({ status: "handled", error: null });
		const log = await db.selectFrom("tbtop_notification_log").select(["channel", "recipient", "status"]).execute();
		expect(log).toEqual([
			{ channel: "mail", recipient: "team@example.test", status: "sent" },
			{ channel: "telegram", recipient: "-1001", status: "sent" },
			{ channel: "telegram", recipient: "42", status: "sent" },
		]);
	});

	test("an enabled channel with nothing to send to fails the submission with its reason", async () => {
		const mail = mailbox();
		const { cms, db } = await makeCms(undefined, { notifications: { mail: mail.transport } });
		await cms.site().settings.set("notifications.form.submission.channels", ["mail", "telegram"]);
		const { action } = await contactAction(cms);
		await cms.handle(post(action, valid));
		const [row] = await rows(db);
		expect(row?.status).toBe("failed");
		expect(row?.error).toBe(
			"Mail notifications are enabled but no recipients are configured. | Telegram notifications are enabled but the bot token or chat IDs are not configured.",
		);
	});

	test("the bell notifies every staff user with a link to the submission", async () => {
		const store = memoryNotifications();
		const { cms } = await makeCms(undefined, { notifications: { bell: { store, staff: () => [{ id: 1 }, { id: 2 }] } } });
		const { action } = await contactAction(cms);
		await cms.handle(post(action, valid));
		expect(store.rows.map((row) => row.owner).sort()).toEqual(["1", "2"]);
		expect(store.rows[0]?.data).toMatchObject({ title: "New form submission", body: "Ada\nada@example.test\nPage: Home" });
		expect(store.rows[0]?.data.actions?.[0]).toMatchObject({ label: "View submission" });
	});

	test("a Liquid override replaces the built-in mail; a Blade row from PHP is ignored", async () => {
		const mail = mailbox();
		const { cms, db } = await makeCms(undefined, { notifications: { mail: mail.transport } });
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["mail"]);
		await settings.set("notifications.mail.recipients", "team@example.test");
		const now = "2026-10-22 00:00:00";
		await db
			.insertInto("tbtop_notification_templates")
			.values({ type: "form.submission", format: "html", locale: "en", subject: "Lead: {{ leadIdentity }}", body: "<p>{{ fields.message }}</p>", created_at: now, updated_at: now })
			.execute();
		const { action } = await contactAction(cms);
		await cms.handle(post(action, { ...valid, message: "<b>hi</b>" }));
		expect(mail.sent[0]).toMatchObject({ subject: "Lead: Ada · ada@example.test", html: "<p>&lt;b&gt;hi&lt;/b&gt;</p>" });

		await db.updateTable("tbtop_notification_templates").set({ body: "@foreach ($fields as $k => $v) {{ $v }} @endforeach" }).execute();
		await cms.handle(post(action, valid));
		expect(mail.sent[1]?.subject).toBe("New form submission");
	});

	test("a throwing block handler fails the submit; a retry runs it again and delivers", async () => {
		let calls = 0;
		const blocks = implementBlocks(cmsBlocks, {
			...cmsBlockImpls,
			ContactForm: {
				...cmsBlockImpls.ContactForm,
				submit: {
					schema: z.object({ name: z.string(), email: z.email(), message: z.string() }),
					handle: () => {
						calls += 1;
						if (calls === 1) {
							throw new Error("CRM is down");
						}
					},
				},
			},
		});
		const { cms, db } = await makeCms(undefined, { blocks } as Partial<CmsConfig>);
		const { action } = await contactAction(cms);
		const response = await cms.handle(post(action, valid));
		expect(response?.status).toBe(500);
		expect(await response?.json()).toEqual({ ok: false, errors: { form: ["Sorry, we could not process your submission. Please try again."] } });
		const [failed] = await rows(db);
		expect(failed).toMatchObject({ status: "failed", error: "CRM is down", handled_at: null });

		const site = cms.site();
		const { retrySubmission } = await import("../src/forms/notifier");
		await retrySubmission(site, await site.submissions.findOrFail(Number(failed?.id)));
		const [retried] = await rows(db);
		expect(retried).toMatchObject({ status: "handled", error: null });
		expect(retried?.handled_at).not.toBeNull();
		expect(calls).toBe(2);
	});

	test("outbox delivery leaves the row pending until cms.tasks.deliver()", async () => {
		const mail = mailbox();
		const { cms, db } = await makeCms(undefined, { forms: { delivery: "outbox" }, notifications: { mail: mail.transport } });
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["mail"]);
		await settings.set("notifications.mail.recipients", "team@example.test");
		const { action } = await contactAction(cms);
		await cms.handle(post(action, valid));
		expect((await rows(db))[0]?.status).toBe("pending");
		expect(mail.sent).toHaveLength(0);
		expect(await cms.tasks.deliver()).toBe(1);
		expect((await rows(db))[0]?.status).toBe("handled");
		expect(mail.sent).toHaveLength(1);
		expect(await cms.tasks.deliver()).toBe(0);
	});
});

describe("field labels in the team's mail (PHP #313)", () => {
	async function mailed(config: Partial<CmsConfig>, path = "/") {
		const mail = mailbox();
		const { cms } = await makeCms(undefined, { ...config, notifications: { mail: mail.transport } });
		await cms.site().settings.set("notifications.form.submission.channels", ["mail"]);
		await cms.site().settings.set("notifications.mail.recipients", "team@example.test");
		const { action } = await contactAction(cms, path);
		expect((await cms.handle(post(action, valid)))?.status).toBe(200);
		return mail.sent[0]?.html ?? "";
	}

	test("ContactForm labels its fields from the CMS strings; values stay as sent", async () => {
		const html = await mailed({});
		expect(html).toContain("<p><strong>Name:</strong> Ada</p>");
		expect(html).toContain("<p><strong>Email:</strong> ada@example.test</p>");
	});

	test("a form's labels(): a blank or missing label and a throwing labels() keep the raw key", async () => {
		const withLabels = (labels: () => Record<string, string>): Partial<CmsConfig> => ({
			blocks: implementBlocks(cmsBlocks, {
				...cmsBlockImpls,
				ContactForm: { ...cmsBlockImpls.ContactForm, submit: { ...cmsBlockImpls.ContactForm.submit, schema: cmsBlockImpls.ContactForm.submit?.schema ?? z.object({}), labels } },
			}),
		});
		const html = await mailed(withLabels(() => ({ name: "Full name", email: " " })));
		expect(html).toContain("<p><strong>Full name:</strong> Ada</p>");
		expect(html).toContain("<p><strong>email:</strong> ada@example.test</p>");
		expect(html).toContain("<p><strong>message:</strong>");
		const thrown = await mailed(
			withLabels(() => {
				throw new Error("no labels");
			}),
		);
		expect(thrown).toContain("<p><strong>name:</strong> Ada</p>");
	});
});
