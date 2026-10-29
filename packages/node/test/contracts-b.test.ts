import { describe, expect, test } from "bun:test";
import { z } from "zod";
import { type CmsConfig, type DeliveryEvent, type MailMessage, type SubmissionEvent, cmsBlockImpls, cmsBlocks, defineBlocks, implementBlocks } from "../src";
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

async function statuses(cms: Made["cms"], requests: () => Request, times: number): Promise<number[]> {
	const out: number[] = [];
	for (let i = 0; i < times; i++) {
		out.push((await cms.handle(requests()))?.status ?? 0);
	}
	return out;
}

describe("q12: a built-in rate limit on form posts", () => {
	test("by default the 11th post from one client in a minute is 429 with Retry-After, and is not stored", async () => {
		const { cms, db } = await makeCms();
		const action = await contactAction(cms);
		const codes = await statuses(cms, () => post(action, valid, { "X-Forwarded-For": "203.0.113.7" }), 10);
		expect(codes.every((code) => code === 200)).toBe(true);
		const over = await cms.handle(post(action, valid, { "X-Forwarded-For": "203.0.113.7" }));
		expect(over?.status).toBe(429);
		expect(Number(over?.headers.get("Retry-After"))).toBeGreaterThan(0);
		expect(await rows(db)).toHaveLength(10);
		// Eleven stored submissions on a fresh server database: past 5 s on a slow CI runner.
	}, 20_000);

	test("clients count apart, and a spoofed first X-Forwarded-For entry does not open a new window", async () => {
		const { cms } = await makeCms(undefined, { forms: { throttle: { max: 2, perSeconds: 60 } } });
		const action = await contactAction(cms);
		const from = (chain: string) => () => post(action, valid, { "X-Forwarded-For": chain });
		expect(await statuses(cms, from("198.51.100.1"), 3)).toEqual([200, 200, 429]);
		expect(await statuses(cms, from("198.51.100.2"), 1)).toEqual([200]);
		// The proxy appended the real address last; what the client sent before it is its own.
		expect(await statuses(cms, from("10.9.9.9, 198.51.100.1"), 1)).toEqual([429]);
	});

	test("every post that reaches the route counts, an invalid or unknown one too", async () => {
		const { cms } = await makeCms(undefined, { forms: { throttle: { max: 2, perSeconds: 60 } } });
		const action = await contactAction(cms);
		expect((await cms.handle(post(action, {})))?.status).toBe(422);
		expect((await cms.handle(post("https://site.test/_cms/forms/en/999/nope", valid)))?.status).toBe(404);
		expect((await cms.handle(post(action, valid)))?.status).toBe(429);
	});

	test("the host's clientIp decides the key over any header", async () => {
		const { cms } = await makeCms(undefined, { forms: { throttle: { max: 1, perSeconds: 60 }, clientIp: (request) => request.headers.get("x-test-ip") } });
		const action = await contactAction(cms);
		expect((await cms.handle(post(action, valid, { "x-test-ip": "a", "X-Forwarded-For": "1.1.1.1" })))?.status).toBe(200);
		expect((await cms.handle(post(action, valid, { "x-test-ip": "a", "X-Forwarded-For": "2.2.2.2" })))?.status).toBe(429);
		expect((await cms.handle(post(action, valid, { "x-test-ip": "b", "X-Forwarded-For": "1.1.1.1" })))?.status).toBe(200);
	});

	test("the window resets after perSeconds", async () => {
		const { cms } = await makeCms(undefined, { forms: { throttle: { max: 1, perSeconds: 0.05 } } });
		const action = await contactAction(cms);
		expect(await statuses(cms, () => post(action, valid), 2)).toEqual([200, 429]);
		await Bun.sleep(80);
		expect(await statuses(cms, () => post(action, valid), 1)).toEqual([200]);
	});

	test("false turns it off; the host's own allow replaces the default; an explicit throttle runs with allow", async () => {
		const off = await makeCms(undefined, { forms: { throttle: false } });
		const offAction = await contactAction(off.cms);
		expect((await statuses(off.cms, () => post(offAction, valid), 12)).every((code) => code === 200)).toBe(true);

		const own = await makeCms(undefined, { forms: { allow: () => true } });
		const ownAction = await contactAction(own.cms);
		expect((await statuses(own.cms, () => post(ownAction, valid), 12)).every((code) => code === 200)).toBe(true);

		let asked = 0;
		const both = await makeCms(undefined, { forms: { throttle: { max: 1, perSeconds: 60 }, allow: () => ++asked > 0 } });
		const bothAction = await contactAction(both.cms);
		expect(await statuses(both.cms, () => post(bothAction, valid), 2)).toEqual([200, 429]);
		expect(asked).toBe(1);
	});

	test("a throttle without a positive max is refused at createCms", async () => {
		await expect(makeCms(null, { forms: { throttle: { max: 0, perSeconds: 60 } } })).rejects.toThrow("forms.throttle");
	});
});

const orderCatalog = defineBlocks({ ...cmsBlocks, ServiceOrder: { props: z.object({ action: z.string(), honeypotField: z.string().optional() }) } });

/** A HasForm block: a service order form a page type template renders, never placed by an editor. */
function orderForm(seen: Record<string, unknown>[] = []): Partial<CmsConfig> {
	return {
		blocks: implementBlocks(orderCatalog, {
			...cmsBlockImpls,
			ServiceOrder: {
				form: () => [],
				resolve: () => ({ action: "" }),
				formName: "service-order",
				submit: {
					schema: z.object({ service: z.string().max(50) }),
					handle: (payload, blockData) => void seen.push({ payload, blockData }),
				},
			},
		}),
	};
}

async function homeId(cms: Made["cms"]): Promise<number> {
	const result = await cms.loadPage(new Request("https://site.test/"));
	if (result.type !== "page") {
		throw new Error("no page");
	}
	return result.props.page.id;
}

describe("q14: named forms (HasForm, Forms::props)", () => {
	test("formProps gives the action and honeypot; a post by name validates, handles with empty data and is stored", async () => {
		const seen: Record<string, unknown>[] = [];
		const { cms, db } = await makeCms(undefined, orderForm(seen));
		const id = await homeId(cms);
		const props = await cms.site().formProps("service-order", id);
		expect(props).toEqual({ action: `/_cms/forms/en/${id}/service-order`, honeypotField: "website" });
		expect((await cms.handle(post(props.action, {})))?.status).toBe(422);
		expect((await cms.handle(post(props.action, { service: "Repair" })))?.status).toBe(200);
		expect(seen).toEqual([{ payload: { service: "Repair" }, blockData: {} }]);
		const stored = await rows(db);
		expect(stored).toHaveLength(1);
		expect(stored[0]).toMatchObject({ block_id: "service-order", kind: "ServiceOrder", status: "handled" });
	});

	test("the name answers on any live page and in another locale; an undeclared name stays 404", async () => {
		const { cms } = await makeCms(undefined, orderForm());
		const id = await homeId(cms);
		const page = await cms.site().pages.find(id);
		if (page === null) {
			throw new Error("no page");
		}
		const { action } = await cms.site().formProps("service-order", page, "uk");
		expect(action).toContain(`/_cms/forms/uk/${id}/service-order`);
		expect((await cms.handle(post(action, { service: "Repair" })))?.status).toBe(200);
		expect((await cms.handle(post(`https://site.test/_cms/forms/en/${id}/ServiceOrder`, { service: "x" })))?.status).toBe(404);
		expect((await cms.handle(post(`https://site.test/_cms/forms/en/${id}/nope`, { service: "x" })))?.status).toBe(404);
	});

	test("formProps throws for a name no block declares", async () => {
		const { cms } = await makeCms(undefined, orderForm());
		await expect(cms.site().formProps("ContactForm", 1)).rejects.toThrow("Form [ContactForm] is not registered.");
	});

	test("a form name without a submit handler, or declared twice, is refused", () => {
		const catalog = defineBlocks({ A: { props: z.object({}) }, B: { props: z.object({}) } });
		const submit = { schema: z.object({}) };
		expect(() => implementBlocks(catalog, { A: { form: () => [], formName: "x" }, B: { form: () => [] } })).toThrow("has no submit handler");
		expect(() => implementBlocks(catalog, { A: { form: () => [], formName: "x", submit }, B: { form: () => [], formName: "x", submit } })).toThrow(
			"Form [x] is declared by both [A] and [B].",
		);
	});
});

describe("q15: host hooks for a submission and a delivery", () => {
	test("onSubmission gets the stored row before the block's handler runs; with store off the row is null", async () => {
		const order: string[] = [];
		const events: SubmissionEvent[] = [];
		const blocks = implementBlocks(cmsBlocks, {
			...cmsBlockImpls,
			ContactForm: { ...cmsBlockImpls.ContactForm, submit: { ...cmsBlockImpls.ContactForm.submit!, handle: () => void order.push("handle") } },
		});
		const onSubmission = (event: SubmissionEvent) => {
			order.push("hook");
			events.push(event);
		};
		const { cms } = await makeCms(undefined, { blocks, forms: { onSubmission } });
		const action = await contactAction(cms);
		expect((await cms.handle(post(action, valid)))?.status).toBe(200);
		expect(order).toEqual(["hook", "handle"]);
		expect(events[0]).toMatchObject({ payload: valid, locale: "en", blockId: action.split("/").at(-1), submission: { status: "pending", payload: valid } });
		expect(events[0]?.page.id).toBe(await homeId(cms));

		const off = await makeCms(undefined, { forms: { store: false, onSubmission } });
		expect((await off.cms.handle(post(await contactAction(off.cms), valid)))?.status).toBe(200);
		expect(events[1]?.submission).toBeNull();
	});

	test("a throwing onSubmission is logged and the submit still succeeds and delivers", async () => {
		const { cms, db } = await makeCms(undefined, {
			forms: {
				onSubmission: () => {
					throw new Error("listener down");
				},
			},
		});
		expect((await cms.handle(post(await contactAction(cms), valid)))?.status).toBe(200);
		expect((await rows(db))[0]).toMatchObject({ status: "handled", error: null });
	});

	test("onDelivery hears every sent and failed pair with its recipient; a throw in it never fails the delivery", async () => {
		const events: DeliveryEvent[] = [];
		const transport = {
			send: async (message: MailMessage) => {
				if (message.to === "down@example.test") {
					throw new Error("SMTP 550");
				}
			},
		};
		const { cms, db } = await makeCms(undefined, {
			notifications: {
				mail: transport,
				onDelivery: (event) => {
					events.push(event);
					throw new Error("listener down");
				},
			},
		});
		const settings = cms.site().settings;
		await settings.set("notifications.form.submission.channels", ["mail"]);
		await settings.set("notifications.mail.recipients", "team@example.test, down@example.test");
		expect((await cms.handle(post(await contactAction(cms), valid)))?.status).toBe(200);
		expect(events.map((event) => [event.status, event.channel, event.to.email, event.stage, event.error])).toEqual([
			["sent", "mail", "team@example.test", "send", null],
			["failed", "mail", "down@example.test", "send", "SMTP 550"],
		]);
		expect(await db.selectFrom("tbtop_notification_log").select(["recipient", "status"]).orderBy("id").execute()).toEqual([
			{ recipient: "team@example.test", status: "sent" },
			{ recipient: "down@example.test", status: "failed" },
		]);
		expect((await rows(db))[0]?.status).toBe("failed");
	});
});
