import { afterAll, describe, expect, test } from "bun:test";
import { GlobalRegistrator } from "@happy-dom/global-registrator";

// A DOM for this file only; the other suites run on Bun's own fetch and Request.
const realFetch = globalThis.fetch;
GlobalRegistrator.register();
const { render, fireEvent, findByRole, findByText } = await import("@testing-library/react");
const { ContactForm } = await import("../src/react");

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
		expect((await findByRole(view.container, "alert")).textContent).toContain("valid email");
		expect(bodies[0]).toEqual({ name: "Ada", email: "", website: "" });
		fireEvent.submit(view.getByTestId("contact-form"));
		expect(await findByText(view.container, "Thanks!")).toBeDefined();
		view.unmount();
	});
});
