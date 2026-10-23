import { describe, expect, test } from "bun:test";
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
	});

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
