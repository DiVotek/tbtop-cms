import { Field, type WithValue } from "@tbtop/admin";
import type { Collection } from "../content/collections";
import type { EntryList, ResolvedEntry } from "../content/entryList";
import { isDict } from "../support/values";
import type { ResolveContext } from "./context";

export const PICK_MODES = ["all", "latest", "related", "manual"] as const;
export type PickMode = (typeof PICK_MODES)[number];

/** The stored value: the editor's mode, the latest-N count, the hand-picked ids. */
export type CollectionValue = { mode: PickMode; limit?: number; ids?: string[] };

export const COLLECTION_VALUE_RULE = "collection_value";
/** How many entries `latest` takes when the editor has not said (CollectionValue::DEFAULT_LIMIT). */
export const DEFAULT_LIMIT = 3;

/** Wire copy for the field's client (lang/en/collections.php `field`). */
const FIELD_STRINGS = {
	labels: { all: "All", latest: "Latest", related: "Related to the page", manual: "Pick by hand" },
	limitLabel: "How many",
	searchPlaceholder: "Search…",
	emptyText: "Nothing found.",
	addLabel: "Add",
	removeLabel: "Remove",
};

/** What the field needs from the CMS: the collection it picks from and its entry search URL. */
export type CollectionBinding = { collection(key: string): Collection | null; searchEndpoint(key: string): string };

const isPickMode = (raw: unknown): raw is PickMode => typeof raw === "string" && (PICK_MODES as readonly string[]).includes(raw);

/** Positive integer ids as strings, once each, in order (CollectionValue::ids). */
export function entryIds(raw: unknown): string[] {
	const out: string[] = [];
	for (const id of Array.isArray(raw) ? raw : []) {
		const text = typeof id === "number" ? String(id) : id;
		if (typeof text === "string" && /^[1-9][0-9]*$/.test(text) && !out.includes(text)) {
			out.push(text);
		}
	}
	return out;
}

/** A stored value as one the picker can run: an unknown or disallowed mode takes the first allowed one. */
export function parseCollectionValue(value: unknown, allowed: PickMode[]): { mode: PickMode; limit: number; ids: string[] } {
	const raw = isDict(value) ? value : {};
	const mode = isPickMode(raw.mode) && allowed.includes(raw.mode) ? raw.mode : (allowed[0] ?? "manual");
	const limit = typeof raw.limit === "number" || (typeof raw.limit === "string" && /^\d+$/.test(raw.limit)) ? Math.max(1, Math.floor(Number(raw.limit))) : DEFAULT_LIMIT;
	return { mode, limit, ids: entryIds(raw.ids) };
}

/**
 * A `collection` field (CollectionField.php): the editor picks how a block
 * gets entries (all, the latest N, those related to the page, or by hand).
 * The site gets the entries as `{id, ...fields}[]`, or one entry or null
 * with `single()`. Its client is the `collection` kind of the admin bundle.
 */
export class CollectionField<N extends string = string> extends Field<N, CollectionValue | null> {
	readonly kind = "collection";
	private binding: CollectionBinding | null = null;

	/** The collection to pick from, by key. */
	of(key: string): this {
		return this.set("collection", key);
	}

	/** One entry or null instead of a list. */
	single(value = true): this {
		return this.set("single", value);
	}

	/** Caps every mode at `count` entries. */
	max(count: number): this {
		if (!Number.isInteger(count) || count < 1) {
			throw new Error(`"${this.name}": max() needs at least 1.`);
		}
		return this.set("max", count);
	}

	/** The modes the editor may choose, the first being the default; `related` drops out for a collection without a `pages` field. */
	modes(modes: PickMode[]): this {
		const unknown = modes.filter((mode) => !isPickMode(mode));
		if (modes.length === 0 || unknown.length > 0) {
			throw new Error(`"${this.name}": modes() takes one or more of ${PICK_MODES.join(", ")}.`);
		}
		return this.set("modes", [...new Set(modes)]);
	}

	/** One choice for every locale. */
	override translatable(value = true): WithValue<this, Record<string, CollectionValue | null>> {
		if (value) {
			throw new Error(`"${this.name}": a collection field is not translatable.`);
		}
		return super.translatable(false);
	}

	collectionKey(): string {
		const key = this.optionValue("collection");
		if (typeof key !== "string" || key === "") {
			throw new Error(`"${this.name}": a collection field needs ->of('<collection key>').`);
		}
		return key;
	}

	isSingle(): boolean {
		return this.optionValue("single") === true;
	}

	/** The cap: `max()`, or 1 for `single()`; null for none. */
	limitCap(): number | null {
		const max = this.optionValue("max");
		const cap = typeof max === "number" ? max : null;
		return this.isSingle() ? Math.min(cap ?? 1, 1) : cap;
	}

	/** The declared modes, `related` dropped when the collection has no related field; never empty. */
	allowedModes(collection: Collection | null = this.target()): PickMode[] {
		const declared = (this.optionValue("modes") as PickMode[] | undefined) ?? [...PICK_MODES];
		const modes = declared.filter((mode) => mode !== "related" || collection?.relatedField !== null);
		return modes.length === 0 ? ["manual"] : modes;
	}

	bindCms(binding: CollectionBinding): this {
		this.binding = binding;
		return this;
	}

	override ruleEntries(): string[] {
		const declared = super.ruleEntries().filter((rule) => rule !== "nullable" && rule !== "array" && !rule.startsWith(COLLECTION_VALUE_RULE));
		const nullable = declared.some((rule) => rule.startsWith("required")) ? declared : ["nullable", ...declared];
		return [...nullable, "array", `${COLLECTION_VALUE_RULE}:${this.collectionKey()},${this.limitCap() ?? 0},${this.allowedModes().join("+")}`];
	}

	override toNode() {
		const key = this.collectionKey();
		const cap = this.limitCap();
		this.set("allowedModes", this.allowedModes());
		this.set("cap", cap);
		this.set("defaultLimit", Math.min(cap ?? DEFAULT_LIMIT, DEFAULT_LIMIT));
		if (this.binding !== null) {
			this.set("searchEndpoint", this.binding.searchEndpoint(key));
			this.set("collectionLabel", this.binding.collection(key)?.label ?? key);
		}
		for (const [name, value] of Object.entries(FIELD_STRINGS)) {
			this.set(name, value);
		}
		return super.toNode();
	}

	private target(): Collection | null {
		return this.binding?.collection(this.collectionKey()) ?? null;
	}
}

/** `collectionField("reviews").of("review")` in a block's form(). */
export function collectionField<const N extends string>(name: N): CollectionField<N> {
	return new CollectionField(name);
}

/** EntryPicker::pick: the stored choice over the collection in the render's locale. */
export async function pickEntries(field: CollectionField, value: unknown, ctx: ResolveContext): Promise<ResolvedEntry[] | ResolvedEntry | null> {
	const key = field.collectionKey();
	const collections = ctx.site.collections;
	const collection = collections.has(key) ? collections.get(key) : null;
	const picked = parseCollectionValue(value, field.allowedModes(collection));
	let list: EntryList = await ctx.collection(key);
	if (picked.mode === "latest") {
		list = list.latest(picked.limit);
	} else if (picked.mode === "related") {
		list = list.relatedTo(ctx.page);
	} else if (picked.mode === "manual") {
		list = list.only(picked.ids);
	}
	const cap = field.limitCap();
	if (cap !== null) {
		list = list.take(cap);
	}
	return field.isSingle() ? list.first() : list.all();
}
