import { afterAll, afterEach, describe, expect, mock, test } from "bun:test";
import { GlobalRegistrator } from "@happy-dom/global-registrator";
import type { AdminClient } from "@tbtop/inertia-admin";
import type { LinkOptions, LinkValue } from "../src/admin-client";

// A DOM for this file only, in its own `bun test` process (`test:dom`): in CI a second happy-dom register in one process left this suite hanging.
const realFetch = globalThis.fetch;
GlobalRegistrator.register();
const { act, cleanup, fireEvent, render, waitFor } = await import("@testing-library/react");
const { ClientProvider, getBlockDescriptor } = await import("@tbtop/inertia-admin");
const { LinkCell, LinkForm, linkFieldClient } = await import("../src/admin-client");

afterEach(() => cleanup());
afterAll(async () => {
	await GlobalRegistrator.unregister();
	Object.defineProperty(globalThis, "fetch", { value: realFetch, configurable: true, writable: true });
});

const NOOP = () => {};
const ENDPOINT = "/admin/link/page-search/select-options/page";

/** Only post() is reached from this field. */
function stubClient(post: (path: string, body?: unknown) => Promise<unknown>): AdminClient {
	return {
		post: post as AdminClient["post"],
		get: async () => ({}),
		patch: async () => ({}),
		delete: async () => ({}),
		upload: async () => ({}),
	} as unknown as AdminClient;
}

function renderForm(props: { options?: LinkOptions; value?: LinkValue | null; onChange?: (next: LinkValue | null) => void; client?: AdminClient }) {
	return render(
		<ClientProvider client={props.client ?? stubClient(async () => ({ options: [] }))}>
			<LinkForm name="cta" id="cta" value={props.value ?? null} onChange={props.onChange ?? NOOP} options={props.options as Record<string, unknown>} />
		</ClientProvider>,
	);
}

/**
 * Lets the stored id's label lookup land inside act(): React's scheduler is a
 * module shared with the other DOM suite, bound to the window that loaded it.
 */
const settle = () => act(async () => await new Promise((resolve) => setTimeout(resolve, 0)));

const page = (id: string | null, anchor: string | null = null): LinkValue => ({ type: "page", page: id, value: null, anchor, label: null });

describe("@tbtop/cms/admin-client", () => {
	test("importing it registers the link field kind", () => {
		expect(linkFieldClient).toBeDefined();
		expect(getBlockDescriptor("link")).toBeDefined();
	});

	test("a type switch clears the other half of the value", () => {
		const onChange = mock(NOOP);
		const { getByText } = renderForm({ value: page("7", "how"), onChange });
		fireEvent.click(getByText("URL"));
		expect(onChange).toHaveBeenCalledWith({ type: "external", page: null, value: null, anchor: null, label: null });
	});

	test("renders only the declared types, in the server's copy", () => {
		const { getByText, queryByText } = renderForm({ options: { types: ["email", "phone"], labels: { email: "Пошта" } } });
		expect(getByText("Пошта")).toBeTruthy();
		expect(queryByText("Page")).toBeNull();
	});

	test("flags a javascript: URL and keeps the raw input", () => {
		const { getByRole } = renderForm({ value: { type: "external", page: null, value: "javascript:alert(1)", anchor: null, label: null } });
		expect(getByRole("alert").textContent).toContain("http(s)");
		expect((getByRole("textbox") as HTMLInputElement).value).toBe("javascript:alert(1)");
	});

	test("picks a page from the search endpoint as a string id, keeping the anchor", async () => {
		const onChange = mock(NOOP);
		const bodies: unknown[] = [];
		const post = async (path: string, body?: unknown) => {
			bodies.push({ path, body });
			return { options: [{ value: "7", label: "About us" }] };
		};
		const { getByRole, getByText } = renderForm({ value: page(null, "how"), options: { searchEndpoint: ENDPOINT }, onChange, client: stubClient(post) });
		fireEvent.click(getByRole("button"));
		await waitFor(() => expect(getByText("About us")).toBeTruthy());
		fireEvent.click(getByText("About us"));
		expect(onChange).toHaveBeenCalledWith(page("7", "how"));
		expect(bodies[0]).toEqual({ path: ENDPOINT, body: { search: "" } });
	});

	test("a Node search answer has no per-locale names: every locale's placeholder is the page's label", async () => {
		const post = async (_path: string, body?: unknown) =>
			(body as { value?: string }).value === "7" ? { option: { value: "7", label: "Contact us" } } : { options: [] };
		const { getByLabelText, getByRole } = renderForm({
			value: page("7"),
			options: { searchEndpoint: ENDPOINT, locales: ["en", "uk"], defaultLocale: "en" },
			client: stubClient(post),
		});
		await settle();
		await waitFor(() => expect(getByRole("button", { name: "Contact us" })).toBeTruthy());
		expect((getByLabelText("Text (en)") as HTMLInputElement).placeholder).toBe("Contact us");
		expect((getByLabelText("Text (uk)") as HTMLInputElement).placeholder).toBe("Contact us");
	});

	test("per-locale names still win when a search sends them", async () => {
		const post = async () => ({ option: { value: "7", label: "Contact us", names: { en: "Contact us", uk: "Звʼязатися" } } });
		const { getByLabelText } = renderForm({
			value: page("7"),
			options: { searchEndpoint: ENDPOINT, locales: ["en", "uk"], defaultLocale: "en" },
			client: stubClient(post),
		});
		await settle();
		await waitFor(() => expect((getByLabelText("Text (uk)") as HTMLInputElement).placeholder).toBe("Звʼязатися"));
	});

	test("a typed label goes under its locale; clearing it stores no label", () => {
		const onChange = mock(NOOP);
		const value: LinkValue = { type: "email", page: null, value: "hi@example.com", anchor: null, label: { uk: "Напишіть" } };
		const { getByLabelText } = renderForm({ value, options: { locales: ["en", "uk"] }, onChange });
		fireEvent.change(getByLabelText("Text (en)"), { target: { value: "Mail us" } });
		expect(onChange).toHaveBeenLastCalledWith({ ...value, label: { uk: "Напишіть", en: "Mail us" } });
		fireEvent.change(getByLabelText("Text (uk)"), { target: { value: " " } });
		expect(onChange).toHaveBeenLastCalledWith({ ...value, label: null });
	});

	test("the table cell summarises a link, the authored label first", () => {
		const cases: Array<[unknown, string]> = [
			[page("7", "how"), "→ Page #7#how"],
			[{ type: "phone", page: null, value: "+380441234567", anchor: null, label: null }, "tel:+380441234567"],
			[{ type: "page", page: "7", value: null, anchor: null, label: { en: "Buy now" } }, "Buy now"],
			[{ nope: true }, "—"],
		];
		for (const [value, expected] of cases) {
			const { container, unmount } = render(<LinkCell value={value as LinkValue} />);
			expect(container.textContent).toBe(expected);
			unmount();
		}
	});
});
