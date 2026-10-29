import { useCallback, useEffect, useRef, useState } from "react";
import { useClient } from "@tbtop/inertia-admin";

export interface EntryOption {
	value: string;
	label: string;
	draft: boolean;
}

function toOption(raw: unknown): EntryOption | null {
	if (raw === null || typeof raw !== "object") {
		return null;
	}
	const bag = raw as Record<string, unknown>;
	if (typeof bag.value !== "string" && typeof bag.value !== "number") {
		return null;
	}
	const value = String(bag.value);
	return {
		value,
		label: typeof bag.label === "string" && bag.label !== "" ? bag.label : `#${value}`,
		draft: bag.draft === true,
	};
}

export function toOptions(response: unknown): EntryOption[] {
	const list = (response as { options?: unknown } | null)?.options;
	return Array.isArray(list) ? list.map(toOption).filter((o): o is EntryOption => o !== null) : [];
}

export interface EntryOptionsState {
	options: EntryOption[];
	isLoading: boolean;
	/** Labels of picked ids, filled from the search and from one lookup of the stored ids. */
	known: Record<string, EntryOption>;
	search: (term: string) => void;
}

/**
 * The `collection` field's picker against the fixed CMS entry-search
 * endpoint (EntrySearchController), over the exported useClient(), as
 * usePageOptions does for the link field.
 */
export function useEntryOptions(endpoint: string | undefined, collection: string | undefined, picked: string[]): EntryOptionsState {
	const client = useClient();
	const [options, setOptions] = useState<EntryOption[]>([]);
	const [isLoading, setLoading] = useState(false);
	const [known, setKnown] = useState<Record<string, EntryOption>>({});
	const latestSearch = useRef(0);

	const remember = useCallback((list: EntryOption[]) => {
		setKnown((prev) => Object.fromEntries([...Object.entries(prev), ...list.map((o) => [o.value, o] as const)]));
	}, []);

	const search = useCallback(
		(term: string) => {
			if (endpoint === undefined || collection === undefined) {
				return;
			}
			// Responses can land out of order; only the newest request may touch the list.
			const ticket = ++latestSearch.current;
			const isCurrent = () => ticket === latestSearch.current;
			setLoading(true);
			client
				.post(endpoint, { collection, search: term })
				.then((response) => {
					const list = toOptions(response);
					remember(list);
					if (isCurrent()) {
						setOptions(list);
					}
				})
				.catch(() => isCurrent() && setOptions([]))
				.finally(() => isCurrent() && setLoading(false));
		},
		[client, endpoint, collection, remember],
	);

	// Stored ids arrive bare; look up the ones without a label once.
	const missing = picked.filter((id) => known[id] === undefined).join(",");
	useEffect(() => {
		if (endpoint === undefined || collection === undefined || missing === "") {
			return;
		}
		let active = true;
		client
			.post(endpoint, { collection, ids: missing.split(",") })
			.then((response) => active && remember(toOptions(response)))
			.catch(() => undefined);
		return () => {
			active = false;
		};
	}, [client, endpoint, collection, missing, remember]);

	return { options, isLoading, known, search };
}
