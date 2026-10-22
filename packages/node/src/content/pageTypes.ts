import { S } from "@tbtop/admin";
import type { ResolveContext } from "../blocks/context";
import { resolveForm } from "../blocks/fields";
import type { Page } from "./page";

/** A page type (PageTypeInterface): tree rules, schema types and custom fields. */
export type PageTypeDef = {
	key: string;
	label?: string;
	/** Child types a page of this type may hold. */
	allowedChildren?: string[];
	requiresParent?: boolean;
	maxDepth?: number;
	/** JSON-LD schema type keys the editor may pick; the first is the default. */
	schemaTypes?: string[];
	/** A subtype names its base type; a base names itself. */
	base?: string;
	/** Custom page fields, stored in `fields`. */
	fields?: (s: S) => unknown[];
	resolveFields?: (input: Record<string, unknown>, ctx: ResolveContext) => Record<string, unknown> | Promise<Record<string, unknown>>;
	/** Block content for a page that has none of its own. */
	template?: (page: Page) => unknown[] | null;
};

export type PageType = Required<Omit<PageTypeDef, "template" | "resolveFields">> & {
	template: (page: Page) => unknown[] | null;
	resolveFields: (input: Record<string, unknown>, ctx: ResolveContext) => Promise<Record<string, unknown>>;
};

export function definePageType(def: PageTypeDef): PageTypeDef {
	return def;
}

const WEB_PAGE_FAMILY = ["WebPage", "AboutPage", "ContactPage", "ItemPage"];

export const builtInPageTypes: PageTypeDef[] = [
	{ key: "page" },
	{ key: "category", allowedChildren: ["category", "article"], maxDepth: 3, schemaTypes: ["CollectionPage"] },
	{ key: "article", requiresParent: true, schemaTypes: ["Article", "NewsArticle", "BlogPosting"] },
];

function normalize(def: PageTypeDef): PageType {
	const fields = def.fields ?? (() => []);
	return {
		key: def.key,
		label: def.label ?? def.key.charAt(0).toUpperCase() + def.key.slice(1),
		allowedChildren: def.allowedChildren ?? [],
		requiresParent: def.requiresParent ?? false,
		maxDepth: def.maxDepth ?? 1,
		schemaTypes: def.schemaTypes ?? WEB_PAGE_FAMILY,
		base: def.base ?? def.key,
		fields,
		template: def.template ?? (() => null),
		resolveFields: async (input, ctx) =>
			def.resolveFields === undefined ? resolveForm(fields(new S()), input, ctx) : def.resolveFields(input, ctx),
	};
}

/** PageTypeRegistry: the built-ins plus the host's, keys unique. */
export class PageTypes {
	private readonly types = new Map<string, PageType>();

	constructor(extra: PageTypeDef[] = []) {
		for (const def of [...builtInPageTypes, ...extra]) {
			if (def.key === "") {
				throw new Error("A page type needs a non-empty key.");
			}
			if (this.types.has(def.key)) {
				throw new Error(`Page type [${def.key}] is already registered.`);
			}
			this.types.set(def.key, normalize(def));
		}
	}

	has(key: string): boolean {
		return this.types.has(key);
	}

	get(key: string): PageType {
		const type = this.types.get(key);
		if (type === undefined) {
			throw new Error(`Page type [${key}] is not registered.`);
		}
		return type;
	}

	all(): PageType[] {
		return [...this.types.values()];
	}

	/** Base types only: what the "new page" picker lists. */
	bases(): PageType[] {
		return this.all().filter((type) => type.base === type.key);
	}

	subtypesOf(base: string): PageType[] {
		return this.all().filter((type) => type.base === base && type.key !== base);
	}

	/** The page's type, then its subtype when registered. */
	forPage(page: Page): PageType[] {
		return [this.types.get(page.type), page.subtype ? this.types.get(page.subtype) : undefined].filter(
			(type): type is PageType => type !== undefined,
		);
	}

	schemaTypesFor(page: Page): string[] {
		return this.forPage(page).at(-1)?.schemaTypes ?? [];
	}
}
