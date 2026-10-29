import { firstText } from "../content/page";
import { baseNode, modifiedAt, publishedAt, type SchemaContext } from "./schemaContext";

export type OpenGraph = { type: string; publishedTime?: string | null; modifiedTime?: string | null; author?: string | null };

/** A JSON-LD schema type a page type may offer (SchemaTypeInterface). */
export type SchemaTypeDef = {
	key: string;
	label?: string;
	openGraph?: (ctx: SchemaContext) => OpenGraph;
	build: (ctx: SchemaContext) => Record<string, unknown>[] | Promise<Record<string, unknown>[]>;
};

const HEADLINE_MAX = 110;
const MAX_PARTS = 100;

function headline(name: string): string {
	const chars = [...name];
	if (chars.length <= HEADLINE_MAX) {
		return name;
	}
	const cut = chars.slice(0, HEADLINE_MAX + 1).join("");
	const space = cut.lastIndexOf(" ");
	return (space !== -1 ? cut.slice(0, space) : chars.slice(0, HEADLINE_MAX).join("")).trimEnd();
}

const webPage = (key: string, label: string): SchemaTypeDef => ({ key, label, build: (ctx) => [baseNode(ctx, key)] });

const article = (key: string, label: string): SchemaTypeDef => ({
	key,
	label,
	openGraph: (ctx) => ({
		type: "article",
		publishedTime: publishedAt(ctx.page),
		modifiedTime: modifiedAt(ctx.page),
		author: typeof ctx.author?.name === "string" ? ctx.author.name : null,
	}),
	build: (ctx) => {
		const node: Record<string, unknown> = {
			...baseNode(ctx, key),
			headline: headline(ctx.name),
			mainEntityOfPage: { "@type": "WebPage", "@id": ctx.url },
		};
		if (ctx.author !== null) {
			node.author = ctx.author;
		}
		if (ctx.publisher !== null) {
			node.publisher = ctx.publisher;
		}
		return [node];
	},
});

/** CollectionPage lists up to 100 live, indexable children as `hasPart`. */
const collectionPage: SchemaTypeDef = {
	key: "CollectionPage",
	label: "Collection page",
	build: async (ctx) => {
		const node = baseNode(ctx, "CollectionPage");
		const fallback = await ctx.site.languages.defaultLocale();
		const children = await ctx.site.pages.children(ctx.page.id, { live: true, indexable: true, limit: MAX_PARTS });
		const paths = await ctx.site.pages.slugs.pathsFor(
			children.map((child) => child.id),
			ctx.locale,
		);
		const parts: Record<string, unknown>[] = [];
		for (const child of children) {
			const name = firstText(child, ["name"], ctx.locale, fallback);
			const path = paths.get(child.id);
			if (name !== "" && path !== undefined) {
				parts.push({ "@type": "WebPage", name, url: ctx.site.absolute(await ctx.site.url(path, ctx.locale)) });
			}
		}
		if (parts.length > 0) {
			node.hasPart = parts;
		}
		return [node];
	},
};

export const FALLBACK_SCHEMA = "WebPage";

export const builtInSchemaTypes: SchemaTypeDef[] = [
	webPage("WebPage", "Web page"),
	webPage("AboutPage", "About page"),
	webPage("ContactPage", "Contact page"),
	webPage("ItemPage", "Item page"),
	article("Article", "Article"),
	article("NewsArticle", "News article"),
	article("BlogPosting", "Blog post"),
	collectionPage,
];

/** SchemaTypeRegistry: built-ins plus the host's, keys unique. */
export class SchemaTypes {
	private readonly types = new Map<string, SchemaTypeDef>();

	constructor(extra: SchemaTypeDef[] = []) {
		for (const def of [...builtInSchemaTypes, ...extra]) {
			if (def.key === "") {
				throw new Error("A schema type needs a non-empty key.");
			}
			if (this.types.has(def.key)) {
				throw new Error(`Schema type [${def.key}] is already registered.`);
			}
			this.types.set(def.key, def);
		}
	}

	has(key: string): boolean {
		return this.types.has(key);
	}

	get(key: string): SchemaTypeDef {
		const type = this.types.get(key);
		if (type === undefined) {
			throw new Error(`Schema type [${key}] is not registered.`);
		}
		return type;
	}

	all(): SchemaTypeDef[] {
		return [...this.types.values()];
	}
}
