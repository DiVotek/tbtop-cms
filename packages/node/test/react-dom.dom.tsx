import { afterAll, describe, expect, test } from "bun:test";
import { GlobalRegistrator } from "@happy-dom/global-registrator";

// A DOM for this file only, in its own `bun test` process (`test:dom`): in CI a second happy-dom register in one process left this suite hanging.
const realFetch = globalThis.fetch;
GlobalRegistrator.register();
const { render, fireEvent, findByRole, findByText, waitFor } = await import("@testing-library/react");
const { ContactForm, useCmsForm } = await import("../src/react");

afterAll(async () => {
	await GlobalRegistrator.unregister();
	Object.defineProperty(globalThis, "fetch", { value: realFetch, configurable: true, writable: true });
});

describe("ContactForm in the browser", () => {
	test("posts JSON, shows the field errors, then the success message", async () => {
		// Plain answers, not Response: happy-dom swaps the global Response, and its body reads differ by runner.
		const answers = [{ ok: false, errors: { email: ["The email field must be a valid email address."] } }, { ok: true }].map((answer) => ({
			json: async () => answer,
		}));
		const bodies: unknown[] = [];
		const stub = (async (_url: string, init?: RequestInit) => {
			bodies.push(JSON.parse(String(init?.body)));
			return answers.shift();
		}) as unknown as typeof fetch;
		// defineProperty, not assignment: another DOM suite in the same process may leave happy-dom's fetch as an accessor.
		for (const target of [globalThis, window]) {
			Object.defineProperty(target, "fetch", { value: stub, configurable: true, writable: true });
		}
		const props = {
			heading: "Write to us",
			submitLabel: "Send",
			successMessage: "Thanks!",
			action: "https://site.test/_cms/forms/en/1/abc",
			honeypotField: "website",
			fields: [
				{ name: "name", label: "Name", type: "text", required: true },
				{ name: "email", label: "Email", type: "email", required: true },
			],
		};
		const view = render(<ContactForm props={props} />);
		const form = view.getByTestId("contact-form") as HTMLFormElement;
		fireEvent.change(view.getByLabelText("Name"), { target: { value: "Ada" } });
		fireEvent.submit(form);
		// Two steps, so a failure says which half stalled: the post, or the render of its answer.
		await waitFor(() => expect(bodies).toHaveLength(1), { timeout: 5000 });
		expect((await findByRole(view.container, "alert", {}, { timeout: 5000 })).textContent).toContain("valid email");
		expect(bodies[0]).toEqual({ name: "Ada", email: "", website: "" });
		fireEvent.submit(view.getByTestId("contact-form"));
		expect(await findByText(view.container, "Thanks!", {}, { timeout: 5000 })).toBeDefined();
		view.unmount();
	});
});

describe("useCmsForm with a controlled form", () => {
	test("submit(data) posts the values; setError shows a client message; reset clears it", async () => {
		const bodies: unknown[] = [];
		const stub = (async (_url: string, init?: RequestInit) => {
			bodies.push(JSON.parse(String(init?.body)));
			return { json: async () => ({ ok: true }) };
		}) as unknown as typeof fetch;
		for (const target of [globalThis, window]) {
			Object.defineProperty(target, "fetch", { value: stub, configurable: true, writable: true });
		}
		function Controlled() {
			const form = useCmsForm("/_cms/forms/en/1/abc");
			return (
				<div>
					<button type="button" onClick={() => form.setError("messenger", "Pick a messenger")}>check</button>
					<button type="button" onClick={() => form.reset()}>reset</button>
					<button type="button" onClick={() => void form.submit({ topic: "audit", name: "Ada" })}>send</button>
					<p data-testid="state">{`${form.status}:${form.errors.messenger?.[0] ?? ""}`}</p>
				</div>
			);
		}
		const view = render(<Controlled />);
		const state = () => view.getByTestId("state").textContent;
		fireEvent.click(view.getByText("check"));
		await waitFor(() => expect(state()).toBe("failed:Pick a messenger"));
		fireEvent.click(view.getByText("reset"));
		await waitFor(() => expect(state()).toBe("idle:"));
		fireEvent.click(view.getByText("send"));
		await waitFor(() => expect(state()).toBe("sent:"), { timeout: 5000 });
		expect(bodies).toEqual([{ topic: "audit", name: "Ada" }]);
		view.unmount();
	});
});

