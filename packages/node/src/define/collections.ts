import type { CollectionDef } from "../content/collections";
import type { Fields, Output } from "./fields";

/** A collection's options in a site file: its key is its name in `defineSite({ collections })`. */
export type CollectionOptions<R extends Fields = Fields> = Omit<CollectionDef, "key" | "form" | "titleField" | "relatedField" | "resolve"> & {
	/** The field that names an entry in the admin; default the first. */
	titleField?: keyof R & string;
	/** The `f.pages()` field the "related" mode reads; default the first, null for none. */
	relatedField?: (keyof R & string) | null;
	resolve?: CollectionDef["resolve"];
};

/** A collection is its fields: each entry is `{ id, ...fields }` as they resolve. */
export type CollectionType<R extends Fields = Fields> = { readonly fields: R; readonly options: CollectionOptions<R> };

/** One entry of a collection as the site gets it. */
export type EntryOf<R extends Fields> = { id: number } & Output<R>;

/** `collection({ author: f.text(), text: f.textarea().translatable() }, { label: "Reviews" })` */
export const collection = <const R extends Fields>(fields: R, options: CollectionOptions<R>): CollectionType<R> => ({ fields, options });

// biome-ignore lint/suspicious/noExplicitAny: any collection, whatever its fields.
type AnyCollection = CollectionType<any>;

const keys = new WeakMap<AnyCollection, string>();

/** defineSite names each collection by its key, so `f.collection(reviews)` finds it. */
export function nameCollections(collections: Record<string, AnyCollection>): void {
	for (const [key, value] of Object.entries(collections)) {
		const known = keys.get(value);
		if (known !== undefined && known !== key) {
			throw new Error(`defineSite: one collection is listed as both "${known}" and "${key}".`);
		}
		keys.set(value, key);
	}
}

/** The key a collection is listed under in `defineSite({ collections })`. */
export function collectionKeyOf(value: AnyCollection | string): string {
	if (typeof value === "string") {
		return value;
	}
	const key = keys.get(value);
	if (key === undefined) {
		throw new Error("f.collection(): the collection is not in defineSite({ collections }).");
	}
	return key;
}
