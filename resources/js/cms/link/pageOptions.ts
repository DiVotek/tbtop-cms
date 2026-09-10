import { useCallback, useEffect, useRef, useState } from "react";
import { useClient } from "@tbtop/inertia-admin";

export interface PageOption {
	value: string;
	label: string;
}

function toOption(raw: unknown): PageOption | null {
	if (raw === null || typeof raw !== "object") {
		return null;
	}
	const bag = raw as Record<string, unknown>;
	if (typeof bag.value !== "string" && typeof bag.value !== "number") {
		return null;
	}
	const value = String(bag.value);
	return { value, label: typeof bag.label === "string" && bag.label !== "" ? bag.label : value };
}

function toOptions(response: unknown): PageOption[] {
	const list = (response as { options?: unknown } | null)?.options;
	return Array.isArray(list) ? list.map(toOption).filter((o): o is PageOption => o !== null) : [];
}

export interface PageOptionsState {
	options: PageOption[];
	isLoading: boolean;
	/** Label for the currently stored id, resolved separately from the list. */
	selectedLabel: string | null;
	search: (term: string) => void;
	noteSeen: (option: PageOption) => void;
}

/**
 * The `link` field's own option loader against a fixed CMS endpoint. The
 * admin's useRemoteOptions/AsyncOptionCombobox are package-internal (not in
 * @tbtop/inertia-admin's exports), so the protocol is re-spoken here over the
 * exported useClient().
 */
export function usePageOptions(endpoint: string | undefined, value: string | null): PageOptionsState {
	const client = useClient();
	const [options, setOptions] = useState<PageOption[]>([]);
	const [isLoading, setLoading] = useState(false);
	const [labels, setLabels] = useState<Record<string, string>>({});
	const seen = useRef<Record<string, string>>({});

	const noteSeen = useCallback((option: PageOption) => {
		seen.current[option.value] = option.label;
		setLabels((prev) => ({ ...prev, [option.value]: option.label }));
	}, []);

	const search = useCallback(
		(term: string) => {
			if (endpoint === undefined) {
				return;
			}
			setLoading(true);
			client
				.post(endpoint, { search: term })
				.then((response) => setOptions(toOptions(response)))
				.catch(() => setOptions([]))
				.finally(() => setLoading(false));
		},
		[client, endpoint],
	);

	// Resolve the stored id's label on mount: a saved value arrives as an id and
	// would otherwise render as a bare number.
	useEffect(() => {
		if (endpoint === undefined || value === null || seen.current[value] !== undefined) {
			return;
		}
		let active = true;
		client
			.post(endpoint, { value })
			.then((response) => {
				const option = toOption((response as { option?: unknown } | null)?.option);
				if (active && option !== null) {
					seen.current[option.value] = option.label;
					setLabels((prev) => ({ ...prev, [option.value]: option.label }));
				}
			})
			.catch(() => undefined);
		return () => {
			active = false;
		};
	}, [client, endpoint, value]);

	return {
		options,
		isLoading,
		selectedLabel: value === null ? null : (labels[value] ?? null),
		search,
		noteSeen,
	};
}
