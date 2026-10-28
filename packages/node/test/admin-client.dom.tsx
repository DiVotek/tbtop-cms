import { afterAll, afterEach, describe, expect, mock, test } from "bun:test";
import { GlobalRegistrator } from "@happy-dom/global-registrator";
import type { AdminClient } from "@tbtop/inertia-admin";
import type { CollectionOptions, CollectionValue, LinkOptions, LinkValue } from "../src/admin-client";

// A DOM for this file only, in its own `bun test` process (`test:dom`): in CI a second happy-dom register in one process left this suite hanging.
const realFetch = globalThis.fetch;
GlobalRegistrator.register();
const { act, cleanup, fireEvent, render, waitFor } = await import("@testing-library/react");
const { ClientProvider, getBlockDescriptor } = await import("@tbtop/inertia-admin");
const { CollectionCell, CollectionForm, LinkCell, LinkForm, linkFieldClient } = await import("../src/admin-client");

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

	test("a type switch clears the target and keeps the label", () => {
		const onChange = mock(NOOP);
		const { getByText } = renderForm({ value: { ...page("7", "how"), label: { en: "Read more" } }, onChange });
		fireEvent.click(getByText("URL"));
		expect(onChange).toHaveBeenCalledWith({ type: "external", page: null, value: null, anchor: null, label: { en: "Read more" } });
	});

	test("editing one locale of a flattened label spreads it over the other locales, with no * key", () => {
		const onChange = mock(NOOP);
		const value: LinkValue = { type: "external", page: null, value: "https://a.test", anchor: null, label: { "*": "Hi" } };
		const { getByLabelText } = renderForm({ value, onChange, options: { types: ["external"], locales: ["en", "uk"] } });
		expect((getByLabelText(/\(uk\)/) as HTMLInputElement).value).toBe("Hi");
		fireEvent.change(getByLabelText(/\(en\)/), { target: { value: "Hello" } });
		expect(onChange).toHaveBeenCalledWith({ ...value, label: { en: "Hello", uk: "Hi" } });
	});

	test("without locales a flattened label stays shared on edit", async () => {
		const { withLabel } = await import("../src/admin-client/link/types");
		const link: LinkValue = { type: "external", value: "https://x.test", page: null, anchor: null, label: { "*": "Read" } };
		expect(withLabel(link, "en", "Open", []).label).toEqual({ "*": "Read", en: "Open" });
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

	test("a failed search says so and retries, instead of showing no matches", async () => {
		let calls = 0;
		const post = async () => {
			calls++;
			if (calls === 1) {
				throw new Error("offline");
			}
			return { options: [{ value: "7", label: "About us" }] };
		};
		const { getByRole, getByText, queryByText } = renderForm({ value: page(null, "how"), options: { searchEndpoint: ENDPOINT }, client: stubClient(post) });
		fireEvent.click(getByRole("button"));
		await waitFor(() => expect(getByText("Could not load pages.")).toBeTruthy());
		expect(queryByText("No pages found.")).toBeNull();
		fireEvent.click(getByText("Retry"));
		await waitFor(() => expect(getByText("About us")).toBeTruthy());
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

const COLLECTION: CollectionOptions = {
	searchEndpoint: "/admin/collections/review/entry-search/select-options/entry",
	allowedModes: ["all", "latest", "related", "manual"],
	cap: null,
	defaultLimit: 3,
	collectionLabel: "Reviews",
};

function renderCollection(props: { options?: CollectionOptions; value?: CollectionValue | null; onChange?: (next: CollectionValue | null) => void; client?: AdminClient }) {
	return render(
		<ClientProvider client={props.client ?? stubClient(async () => ({ options: [] }))}>
			<CollectionForm name="reviews" id="reviews" value={props.value ?? null} onChange={props.onChange ?? NOOP} options={(props.options ?? COLLECTION) as Record<string, unknown>} />
		</ClientProvider>,
	);
}

describe("collection field client", () => {
	test("a mode switch starts from that mode's defaults; one allowed mode hides the switch", () => {
		const onChange = mock(NOOP);
		const { getByText, unmount } = renderCollection({ value: { mode: "manual", ids: ["4"] }, onChange });
		fireEvent.click(getByText("Latest"));
		expect(onChange).toHaveBeenCalledWith({ mode: "latest", limit: 3 });
		unmount();
		const { queryByText } = renderCollection({ options: { ...COLLECTION, allowedModes: ["manual"] } });
		expect(queryByText("All")).toBeNull();
	});

	test("stored ids are named from one {values} lookup; a searched entry is added at the end", async () => {
		const post = mock(async (_url: string, body?: unknown) => {
			const payload = body as { values?: string[]; search?: string };
			return payload.values !== undefined ? { options: [{ value: "4", label: "Anna" }] } : { options: [{ value: "7", label: "Boris (draft)" }] };
		});
		const onChange = mock(NOOP);
		const { getByText, findByText } = renderCollection({ value: { mode: "manual", ids: ["4"] }, onChange, client: stubClient(post) });
		expect(await findByText("Anna")).toBeTruthy();
		fireEvent.click(getByText("Add · Reviews"));
		fireEvent.click(await findByText("Boris (draft)"));
		await waitFor(() => expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["4", "7"] }));
		expect(post.mock.calls[0]).toEqual(["/admin/collections/review/entry-search/select-options/entry", { values: ["4"] }]);
		// The popover's close and focus return run on timers: let them finish while the DOM is still here.
		await settle();
	});

	test("picks move and go; the cell summarises the mode", async () => {
		const onChange = mock(NOOP);
		const { getAllByLabelText } = renderCollection({ value: { mode: "manual", ids: ["1", "2"] }, onChange });
		fireEvent.click(getAllByLabelText("Down")[0] as HTMLElement);
		expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["2", "1"] });
		fireEvent.click(getAllByLabelText("Remove")[1] as HTMLElement);
		expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["1"] });
		const { container } = render(<CollectionCell value={{ mode: "latest", limit: 4 }} options={COLLECTION as Record<string, unknown>} />);
		expect(container.textContent).toBe("Latest: 4");
		await settle();
	});
});
