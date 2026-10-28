import { S, walk } from "@tbtop/admin";
import type { ResolveContext } from "../blocks/context";
import { PagesField } from "../blocks/cmsFields";
import { CollectionField } from "../blocks/collectionField";
import { formFields } from "../blocks/fields";

/**
 * A kind of record with no page of its own: reviews, team members, offices
 * (Collection.php). Its form is code; its entries are `tbtop_entries` rows
 * edited in the admin and read on the site through a `collection` field or
 * `site.collection(key)`.
 */
export type CollectionDef = {
	/** `[a-z][a-z0-9_-]*`, at most 64 characters: stored in `tbtop_entries.collection` and used in admin URLs. */
	key: string;
	/** Plural name: the menu item and the list title. */
	label: string;
	/** The "Add …" button; default `label`. */
	singularLabel?: string;
	/** The entry form. `status`, `lock_version` and `id` are reserved; a `collection` field is refused. */
	form: (s: S) => unknown[];
	/** The field that names an entry in the admin list and pickers; default the first field. */
	titleField?: string;
	/** The `pages` field the "related" mode filters on; default the first one, none without one. */
	relatedField?: string | null;
	icon?: string;
	/** Default `content`. */
	navigationGroup?: string;
	/** Default 50. */
	navigationOrder?: number;
	/**
	 * One entry as the site gets it, from values flattened to the locale;
	 * default FormResolver over `form`. `ctx.page` is null: an entry is
	 * resolved once per locale, not per page.
	 */
	resolve?: (input: Record<string, unknown>, ctx: ResolveContext) => Record<string, unknown> | Promise<Record<string, unknown>>;
};

/** A registered collection with its defaults filled in. */
export type Collection = Required<Omit<CollectionDef, "resolve" | "relatedField">> & {
	relatedField: string | null;
	resolve: NonNullable<CollectionDef["resolve"]> | null;
	/** The form's top-level field names, in order. */
	fieldNames: string[];
};

const KEY = /^[a-z][a-z0-9_-]{0,63}$/;
/** Entry columns the form must not shadow (CollectionRegistry::RESERVED_FIELDS). */
const RESERVED_FIELDS = ["id", "status", "lock_version"];

/** The registered collections: the built-ins first, then the host's (CollectionRegistry.php). */
export class Collections {
	private readonly byKey = new Map<string, Collection>();

	constructor(defs: readonly CollectionDef[] = []) {
		for (const def of defs) {
			this.add(def);
		}
	}

	has(key: string): boolean {
		return this.byKey.has(key);
	}

	get(key: string): Collection {
		const found = this.byKey.get(key);
		if (found === undefined) {
			throw new Error(`Collection [${key}] is not registered.`);
		}
		return found;
	}

	all(): Collection[] {
		return [...this.byKey.values()];
	}

	private add(def: CollectionDef): void {
		if (!KEY.test(def.key)) {
			throw new Error(`Collection key [${def.key}] must match ${KEY.source}.`);
		}
		if (this.byKey.has(def.key)) {
			throw new Error(`Collection [${def.key}] is registered twice.`);
		}
		// The admin names a collection's table `entries_{key}` with - as _, so team-a and team_a would share it.
		const clash = [...this.byKey.keys()].find((known) => known.replaceAll("-", "_") === def.key.replaceAll("-", "_"));
		if (clash !== undefined) {
			throw new Error(`Collection [${def.key}] clashes with [${clash}]: keys may not differ only by - and _.`);
		}
		const form = def.form(new S());
		const fields = formFields(form);
		const names = fields.map((field) => field.name);
		const reserved = names.filter((name) => RESERVED_FIELDS.includes(name));
		if (reserved.length > 0) {
			throw new Error(`Collection [${def.key}]: the field name(s) ${reserved.join(", ")} are reserved.`);
		}
		if (walk.collectAll(form, isCollectionField).length > 0) {
			throw new Error(`Collection [${def.key}]: an entry cannot pick entries (a collection field inside a collection).`);
		}
		const titleField = def.titleField ?? names[0];
		if (titleField === undefined) {
			throw new Error(`Collection [${def.key}] has no fields.`);
		}
		// A site file in plain JS has no types to catch a typo, and a wrong name leaves every entry untitled or unrelated.
		if (!names.includes(titleField)) {
			throw new Error(`Collection [${def.key}]: titleField [${titleField}] is not a field of its form.`);
		}
		const pages = fields.filter((field) => field instanceof PagesField).map((field) => field.name);
		if (def.relatedField !== undefined && def.relatedField !== null && !pages.includes(def.relatedField)) {
			throw new Error(`Collection [${def.key}]: relatedField [${def.relatedField}] is not a pages field of its form.`);
		}
		this.byKey.set(def.key, {
			key: def.key,
			label: def.label,
			singularLabel: def.singularLabel ?? def.label,
			form: def.form,
			titleField,
			relatedField: def.relatedField === undefined ? (pages[0] ?? null) : def.relatedField,
			icon: def.icon ?? "list",
			navigationGroup: def.navigationGroup ?? "content",
			navigationOrder: def.navigationOrder ?? 50,
			resolve: def.resolve ?? null,
			fieldNames: names,
		});
	}
}

/**
 * Every collection field in the block and page-type forms must name a
 * registered collection: a typo would otherwise build, then throw on the
 * first render of every page that uses it.
 */
export function assertCollectionTargets(collections: Collections, forms: { owner: string; form: (s: S) => unknown[] }[]): void {
	for (const { owner, form } of forms) {
		for (const field of walk.collectAll(form(new S()), isCollectionField) as CollectionField[]) {
			const key = field.collectionKey();
			if (!collections.has(key)) {
				throw new Error(`${owner}: the collection field [${field.name}] picks from [${key}], which is not a registered collection.`);
			}
		}
	}
}

/** Any depth: a collection field inside a repeater picks entries all the same. */
const isCollectionField = (value: unknown): boolean => value instanceof CollectionField;
