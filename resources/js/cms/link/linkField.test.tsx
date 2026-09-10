import { describe, expect, it, mock } from "bun:test";
import { fireEvent, render, waitFor } from "@testing-library/react";
import { type AdminClient, ClientProvider } from "@tbtop/inertia-admin";
import { LinkCell, LinkForm } from "./linkField";
import type { LinkOptions, LinkValue } from "./types";

const NOOP = () => {};

const ENDPOINT = "/admin/link/page-search";

/** Only post() is reached from this field; the rest of AdminClient is inert here. */
function stubClient(post: AdminClient["post"]): AdminClient {
	return {
		post,
		get: async () => ({}),
		patch: async () => ({}),
		delete: async () => ({}),
		upload: async () => ({}),
	};
}

interface FormHarness {
	options?: LinkOptions;
	value?: LinkValue | null;
	onChange?: (next: LinkValue | null) => void;
	client?: AdminClient;
}

function renderForm({ options, value = null, onChange = NOOP, client }: FormHarness = {}) {
	return render(
		<ClientProvider client={client ?? stubClient(async () => ({ options: [] }))}>
			<LinkForm name="cta" value={value} onChange={onChange} options={options as Record<string, unknown>} />
		</ClientProvider>,
	);
}

describe("LinkForm type switch", () => {
	it("clears the other half of the value when the type changes", () => {
		const onChange = mock(NOOP);
		const { getByText } = renderForm({
			value: { type: "email", page: null, value: "hi@example.com", anchor: null },
			onChange,
		});

		fireEvent.click(getByText("Phone"));

		expect(onChange).toHaveBeenCalledWith({ type: "phone", page: null, value: null, anchor: null });
	});

	it("drops a page id and its anchor when switching away from page", () => {
		const onChange = mock(NOOP);
		const { getByText } = renderForm({
			value: { type: "page", page: "7", value: null, anchor: "how" },
			onChange,
		});

		fireEvent.click(getByText("URL"));

		expect(onChange).toHaveBeenCalledWith({ type: "external", page: null, value: null, anchor: null });
	});

	it("renders only the declared types", () => {
		const { getByText, queryByText } = renderForm({ options: { types: ["email", "phone"] } });

		expect(getByText("Email")).toBeTruthy();
		expect(queryByText("Page")).toBeNull();
	});
});

describe("LinkForm inline validation", () => {
	it("shows the message for an invalid email but keeps the raw input on screen", () => {
		const { getByRole } = renderForm({
			value: { type: "email", page: null, value: "nope", anchor: null },
		});

		expect(getByRole("alert").textContent).toContain("valid email");
		expect((getByRole("textbox") as HTMLInputElement).value).toBe("nope");
	});

	it("accepts an allowlisted messenger deep link with no message", () => {
		const { queryByRole } = renderForm({
			value: { type: "external", page: null, value: "viber://chat?number=%2B380441234567", anchor: null },
		});

		expect(queryByRole("alert")).toBeNull();
	});

	it("rejects a javascript: url", () => {
		const { getByRole } = renderForm({
			value: { type: "external", page: null, value: "javascript:alert(1)", anchor: null },
		});

		expect(getByRole("alert").textContent).toContain("http(s)");
	});

	it("stays quiet on an untouched field", () => {
		const { queryByRole } = renderForm({
			value: { type: "email", page: null, value: null, anchor: null },
		});

		expect(queryByRole("alert")).toBeNull();
	});
});

describe("LinkForm page picker", () => {
	it("emits the picked page id as a string", async () => {
		const onChange = mock(NOOP);
		const post = mock(async () => ({ options: [{ value: "7", label: "About us" }] }));
		const { getByRole, getByText } = renderForm({
			value: { type: "page", page: null, value: null, anchor: null },
			options: { searchEndpoint: ENDPOINT },
			onChange,
			client: stubClient(post as unknown as AdminClient["post"]),
		});

		fireEvent.click(getByRole("button"));

		await waitFor(() => expect(getByText("About us")).toBeTruthy());
		fireEvent.click(getByText("About us"));

		expect(onChange).toHaveBeenCalledWith({ type: "page", page: "7", value: null, anchor: null });
	});

	it("resolves the stored id to a label instead of showing the bare number", async () => {
		const post = mock(async (_path: string, body?: unknown) =>
			(body as { value?: string }).value === "7"
				? { option: { value: "7", label: "About us" } }
				: { options: [] },
		);
		const { getByRole } = renderForm({
			value: { type: "page", page: "7", value: null, anchor: null },
			options: { searchEndpoint: ENDPOINT },
			client: stubClient(post as unknown as AdminClient["post"]),
		});

		await waitFor(() => expect(getByRole("button").textContent).toContain("About us"));
	});

	it("keeps the anchor when a page is picked", async () => {
		const onChange = mock(NOOP);
		const post = mock(async () => ({ options: [{ value: "7", label: "About us" }] }));
		const { getByRole, getByText } = renderForm({
			value: { type: "page", page: null, value: null, anchor: "how" },
			options: { searchEndpoint: ENDPOINT },
			onChange,
			client: stubClient(post as unknown as AdminClient["post"]),
		});

		fireEvent.click(getByRole("button"));
		await waitFor(() => expect(getByText("About us")).toBeTruthy());
		fireEvent.click(getByText("About us"));

		expect(onChange).toHaveBeenCalledWith({ type: "page", page: "7", value: null, anchor: "how" });
	});

	it("flags an anchor carrying a hash", () => {
		const { getByRole } = renderForm({
			value: { type: "page", page: "7", value: null, anchor: "#how" },
			options: { searchEndpoint: ENDPOINT },
		});

		expect(getByRole("alert").textContent).toContain("no #");
	});
});

describe("LinkCell", () => {
	it("summarises each type", () => {
		const cases: Array<[LinkValue, string]> = [
			[{ type: "page", page: "7", value: null, anchor: null }, "→ Page #7"],
			[{ type: "page", page: "7", value: null, anchor: "how" }, "→ Page #7#how"],
			[{ type: "external", page: null, value: "https://example.com", anchor: null }, "https://example.com"],
			[{ type: "email", page: null, value: "hi@example.com", anchor: null }, "mailto:hi@example.com"],
			[{ type: "phone", page: null, value: "+380441234567", anchor: null }, "tel:+380441234567"],
		];

		for (const [value, expected] of cases) {
			const { container, unmount } = render(<LinkCell value={value} />);
			expect(container.textContent).toBe(expected);
			unmount();
		}
	});

	it("renders a dash for a malformed value", () => {
		const { container } = render(<LinkCell value={{ nope: true } as unknown as LinkValue} />);

		expect(container.textContent).toBe("—");
	});
});
