export const PICK_MODES = ["all", "latest", "related", "manual"] as const;

export type PickMode = (typeof PICK_MODES)[number];

/** The stored value of a `collection` field (CollectionValue on the server). */
export interface CollectionValue {
	mode: PickMode;
	limit?: number;
	ids?: string[];
}

/** The node options CollectionField.toNode() sends. */
export interface CollectionOptions {
	searchEndpoint?: string;
	allowedModes?: PickMode[];
	cap?: number | null;
	defaultLimit?: number;
	collectionLabel?: string;
	labels?: Partial<Record<PickMode, string>>;
	limitLabel?: string;
	searchPlaceholder?: string;
	emptyText?: string;
	addLabel?: string;
	removeLabel?: string;
	single?: boolean;
}

function isPickMode(raw: unknown): raw is PickMode {
	return typeof raw === "string" && (PICK_MODES as readonly string[]).includes(raw);
}

export function allowedModes(options: CollectionOptions | undefined): PickMode[] {
	const declared = (options?.allowedModes ?? []).filter(isPickMode);
	return declared.length > 0 ? declared : [...PICK_MODES];
}

function ids(raw: unknown): string[] {
	if (!Array.isArray(raw)) {
		return [];
	}
	const out: string[] = [];
	for (const id of raw) {
		const text = typeof id === "number" ? String(id) : id;
		if (typeof text === "string" && /^[1-9][0-9]*$/.test(text) && !out.includes(text)) {
			out.push(text);
		}
	}
	return out;
}

/** Anything stored becomes a well-formed value; an unknown mode takes the field's first one, as the server does. */
export function parseCollectionValue(raw: unknown, options: CollectionOptions | undefined): CollectionValue {
	const modes = allowedModes(options);
	const bag = raw !== null && typeof raw === "object" && !Array.isArray(raw) ? (raw as Record<string, unknown>) : {};
	const mode = isPickMode(bag.mode) && modes.includes(bag.mode) ? bag.mode : (modes[0] ?? "manual");
	const limit = typeof bag.limit === "number" && bag.limit >= 1 ? Math.floor(bag.limit) : undefined;
	return { mode, ...(limit === undefined ? {} : { limit }), ids: ids(bag.ids) };
}

/** The value a mode switch starts from: the other modes' settings are dropped, as with the link type switch. */
export function valueForMode(mode: PickMode, options: CollectionOptions | undefined): CollectionValue {
	if (mode === "latest") {
		return { mode, limit: options?.defaultLimit ?? 3 };
	}
	return mode === "manual" ? { mode, ids: [] } : { mode };
}
