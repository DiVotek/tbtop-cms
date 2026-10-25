import { cmsBlockImpls, implementBlocks, linkField, pagesField } from "@tbtop/cms";
import { catalog } from "./catalog";

const cleanString = (value: unknown): string | null => {
	if (typeof value !== "string") {
		return null;
	}
	const trimmed = value.trim();
	return trimmed === "" ? null : trimmed;
};

const cleanRepeater = <T>(value: unknown, map: (row: Record<string, unknown>) => T | null): T[] =>
	(Array.isArray(value) ? value : [])
		.filter((row): row is Record<string, unknown> => row !== null && typeof row === "object")
		.map(map)
		.filter((row): row is T => row !== null);

export const siteBlocks = implementBlocks(catalog, {
	...cmsBlockImpls,
	SiteNav: {
		form: (s) => [
			s.text("siteName").label("Site name").required(),
			s.text("tagline").label("Tagline").translatable(),
			s
				.repeater("links")
				.label("Links")
				.fields([s.text("label").label("Label").translatable().required(), s.text("href").label("Path or URL").required()]),
		],
		resolve: (input) => ({
			siteName: cleanString(input.siteName) ?? "Site",
			tagline: cleanString(input.tagline),
			links: cleanRepeater(input.links, (row) => {
				const label = cleanString(row.label);
				const href = cleanString(row.href);
				return label && href ? { label, href } : null;
			}),
		}),
	},
	SiteFooter: {
		form: (s) => [
			s.text("siteName").label("Site name").required(),
			s.text("phone").label("Phone"),
			s.text("email").label("Email"),
			s.text("note").label("Note").translatable(),
			s
				.repeater("links")
				.label("Links")
				.fields([s.text("label").label("Label").translatable().required(), s.text("href").label("Path").required()]),
		],
		resolve: (input) => ({
			siteName: cleanString(input.siteName) ?? "Site",
			phone: cleanString(input.phone),
			email: cleanString(input.email),
			note: cleanString(input.note),
			links: cleanRepeater(input.links, (row) => {
				const label = cleanString(row.label);
				const href = cleanString(row.href);
				return label && href ? { label, href } : null;
			}),
		}),
	},
	CategoryCards: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s
				.repeater("items")
				.label("Categories")
				.fields([
					s.text("title").label("Title").translatable().required(),
					s.text("image").label("Image URL"),
					linkField("link").label("Link").types(["page", "external"]),
				]),
		],
		resolve: async (input, ctx) => ({
			heading: cleanString(input.heading),
			items: await Promise.all(
				cleanRepeater(input.items, (row) => {
					const title = cleanString(row.title);
					return title ? { title, image: cleanString(row.image), link: row.link } : null;
				}).map(async (item) => ({
					title: item.title,
					image: item.image,
					link: await ctx.link(item.link),
				})),
			),
		}),
	},
	StatsStrip: {
		form: (s) => [
			s
				.repeater("items")
				.label("Stats")
				.fields([s.text("value").label("Value").required(), s.text("label").label("Label").translatable().required()]),
		],
		resolve: (input) => ({
			items: cleanRepeater(input.items, (row) => {
				const value = cleanString(row.value);
				const label = cleanString(row.label);
				return value && label ? { value, label } : null;
			}),
		}),
	},
	AboutSection: {
		form: (s) => [s.text("heading").label("Heading").translatable(), s.textarea("body").label("Body").translatable().required()],
		resolve: (input) => ({
			heading: cleanString(input.heading),
			body: cleanString(input.body) ?? "",
		}),
	},
	FeatureGrid: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s
				.repeater("items")
				.label("Items")
				.fields([s.text("title").label("Title").translatable().required(), s.textarea("text").label("Text").translatable()]),
		],
		resolve: (input) => ({
			heading: cleanString(input.heading),
			items: cleanRepeater(input.items, (row) => {
				const title = cleanString(row.title);
				return title ? { title, text: cleanString(row.text) } : null;
			}),
		}),
	},
	ProcessSteps: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s
				.repeater("steps")
				.label("Steps")
				.fields([s.text("title").label("Title").translatable().required(), s.textarea("text").label("Text").translatable().required()]),
		],
		resolve: (input) => ({
			heading: cleanString(input.heading),
			steps: cleanRepeater(input.steps, (row) => {
				const title = cleanString(row.title);
				const text = cleanString(row.text);
				return title && text ? { title, text } : null;
			}),
		}),
	},
	ProductGrid: {
		form: (s) => [s.text("heading").label("Heading").translatable(), pagesField("products").label("Products").of("article", "product")],
		resolve: async (input, ctx) => ({
			heading: cleanString(input.heading),
			products: await ctx.pages(input.products),
		}),
	},
	ProductDetail: {
		form: (s) => [s.text("note").label("Note under price").translatable()],
		resolve: (input, ctx) => ({
			price: typeof ctx.fields.price === "number" ? ctx.fields.price : null,
			unit: typeof ctx.fields.unit === "string" ? ctx.fields.unit : null,
			note: cleanString(input.note),
		}),
	},
	StoreList: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s
				.repeater("locations")
				.label("Locations")
				.fields([s.text("city").label("City").translatable(), s.text("address").label("Address").translatable().required()]),
		],
		resolve: (input) => ({
			heading: cleanString(input.heading),
			locations: cleanRepeater(input.locations, (row) => {
				const address = cleanString(row.address);
				return address ? { city: cleanString(row.city), address } : null;
			}),
		}),
	},
	CoopBenefits: {
		form: (s) => [
			s.text("heading").label("Heading").translatable(),
			s.repeater("items").label("Benefits").fields([s.text("text").label("Item").translatable().required()]),
			s.textarea("body").label("Closing text").translatable(),
		],
		resolve: (input) => ({
			heading: cleanString(input.heading),
			items: cleanRepeater(input.items, (row) => cleanString(row.text)).filter((t): t is string => t !== null),
			body: cleanString(input.body),
		}),
	},
});
