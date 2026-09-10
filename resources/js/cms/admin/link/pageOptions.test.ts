import { describe, expect, it } from "bun:test";
import { act, renderHook, waitFor } from "@testing-library/react";
import { type AdminClient, ClientProvider } from "@tbtop/inertia-admin";
import { createElement, type ReactNode } from "react";
import { usePageOptions } from "./pageOptions";

const ENDPOINT = "/admin/link/page-search";

interface Deferred {
	term: string;
	resolve: (response: unknown) => void;
}

/** Each search() call parks its request here; the test settles them in whatever order it wants. */
function deferredClient(pending: Deferred[]): AdminClient {
	return {
		post: (_url: string, body?: unknown) => {
			const { promise, resolve } = Promise.withResolvers<unknown>();
			pending.push({ term: String((body as { search?: string }).search), resolve });
			return promise;
		},
		get: async () => ({}),
		patch: async () => ({}),
		delete: async () => ({}),
		upload: async () => ({}),
	};
}

describe("usePageOptions search ordering", () => {
	it("ignores a slower response for an earlier term", async () => {
		const pending: Deferred[] = [];
		const client = deferredClient(pending);
		const wrapper = ({ children }: { children: ReactNode }) => createElement(ClientProvider, { client, children });
		const { result } = renderHook(() => usePageOptions(ENDPOINT, null), { wrapper });

		act(() => {
			result.current.search("a");
			result.current.search("ab");
		});
		expect(pending.map((p) => p.term)).toEqual(["a", "ab"]);

		await act(async () => {
			pending[1].resolve({ options: [{ value: "2", label: "About" }] });
		});
		await waitFor(() => expect(result.current.isLoading).toBe(false));

		await act(async () => {
			pending[0].resolve({ options: [{ value: "1", label: "Archive" }] });
		});

		expect(result.current.options.map((o) => o.label)).toEqual(["About"]);
		expect(result.current.isLoading).toBe(false);
	});
});
