import { describe, expect, it, mock } from "bun:test";
import { fireEvent, render, waitFor } from "@testing-library/react";
import { type AdminClient, ClientProvider } from "@tbtop/inertia-admin";
import { CollectionCell, CollectionForm } from "./collectionField";
import type { CollectionOptions, CollectionValue } from "./types";
import { parseCollectionValue } from "./types";

const NOOP = () => {};

const OPTIONS: CollectionOptions = {
	searchEndpoint: "/admin/entry-search",
	collection: "review",
	allowedModes: ["all", "latest", "related", "manual"],
	cap: null,
	defaultLimit: 3,
	collectionLabel: "Reviews",
};

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
	options?: CollectionOptions;
	value?: CollectionValue | null;
	onChange?: (next: CollectionValue | null) => void;
	client?: AdminClient;
}

function renderForm({ options = OPTIONS, value = null, onChange = NOOP, client }: FormHarness = {}) {
	return render(
		<ClientProvider client={client ?? stubClient(async () => ({ options: [] }))}>
			<CollectionForm name="reviews" id="reviews" value={value} onChange={onChange} options={options as Record<string, unknown>} />
		</ClientProvider>,
	);
}

describe("CollectionForm modes", () => {
	it("starts a mode switch from that mode's defaults", () => {
		const onChange = mock(NOOP);
		const { getByText } = renderForm({ value: { mode: "manual", ids: ["4"] }, onChange });

		fireEvent.click(getByText("Latest"));

		expect(onChange).toHaveBeenCalledWith({ mode: "latest", limit: 3 });
	});

	it("offers only the allowed modes and hides the switch for one", () => {
		const { queryByText, rerender } = renderForm({ options: { ...OPTIONS, allowedModes: ["all", "manual"] } });
		expect(queryByText("Latest")).toBeNull();
		expect(queryByText("Pick by hand")).toBeTruthy();

		rerender(
			<ClientProvider client={stubClient(async () => ({ options: [] }))}>
				<CollectionForm name="reviews" value={null} onChange={NOOP} options={{ ...OPTIONS, allowedModes: ["manual"] } as Record<string, unknown>} />
			</ClientProvider>,
		);
		expect(queryByText("All")).toBeNull();
	});
});

describe("CollectionForm manual picks", () => {
	it("labels stored ids from one lookup and adds a searched entry at the end", async () => {
		const post = mock(async (_url: string, body: unknown) => {
			const payload = body as { ids?: string[]; search?: string };
			if (payload.ids !== undefined) {
				return { options: [{ value: "4", label: "Anna", draft: false }] };
			}
			return { options: [{ value: "7", label: "Boris", draft: true }] };
		});
		const onChange = mock(NOOP);
		const { getByText, findByText } = renderForm({ value: { mode: "manual", ids: ["4"] }, onChange, client: stubClient(post as AdminClient["post"]) });

		expect(await findByText("Anna")).toBeTruthy();
		fireEvent.click(getByText("Add · Reviews"));
		fireEvent.click(await findByText("Boris"));

		await waitFor(() => expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["4", "7"] }));
		expect(post.mock.calls[0]?.[1]).toEqual({ collection: "review", ids: ["4"] });
	});

	it("moves and removes picks", () => {
		const onChange = mock(NOOP);
		const { getAllByLabelText } = renderForm({ value: { mode: "manual", ids: ["1", "2"] }, onChange });

		fireEvent.click(getAllByLabelText("Down")[0]);
		expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["2", "1"] });

		fireEvent.click(getAllByLabelText("Remove")[1]);
		expect(onChange).toHaveBeenCalledWith({ mode: "manual", ids: ["1"] });
	});
});

describe("parseCollectionValue", () => {
	it("falls back to the first allowed mode and drops malformed ids", () => {
		expect(parseCollectionValue({ mode: "random", ids: ["3", "x", 3, "0"] }, OPTIONS)).toEqual({ mode: "all", ids: ["3"] });
		expect(parseCollectionValue(null, { ...OPTIONS, allowedModes: ["manual"] })).toEqual({ mode: "manual", ids: [] });
	});
});

describe("CollectionCell", () => {
	it("summarises the mode", () => {
		const { container } = render(<CollectionCell value={{ mode: "latest", limit: 4 }} options={OPTIONS as Record<string, unknown>} />);

		expect(container.textContent).toBe("Latest: 4");
	});
});
