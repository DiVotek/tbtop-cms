import { type Page, pageFromRow } from "../../content/page";
import { allowedUrl } from "../../content/richtext";
import { whereLive } from "../../content/pages";
import type { Site } from "../../site";
import { ownDescription, ownTitle } from "../schemaContext";
import { richtextMarkdown } from "./richtext";
import { escapeMarkdown, heading, inline, linkTarget, list, plainText } from "./text";

/**
 * What a block's and a page type's `toMarkdown` get (MarkdownContext.php):
 * the page and locale, its canonical URL and resolved type fields, and
 * helpers that escape stored text so a host does not build markdown by hand.
 * Read other pages and collections through `site`.
 */
export type MarkdownContext = {
	page: Page;
	locale: string;
	defaultLocale: string;
	/** The page's canonical URL (the HTML version). */
	url: string;
	/** The page's resolved type fields (`page.fields`). */
	fields: Record<string, unknown>;
	site: Site;
	/** Plain text, escaped: blank lines split paragraphs, single newlines stay line breaks. */
	text(text: string | null | undefined): string;
	/** Characters with markdown meaning, backslash-escaped, for text inside your own markup. */
	escape(text: string): string;
	/** `## text` (escaped); '' for empty text. The page heading is the only H1. */
	heading(text: string, level?: number): string;
	/** `[text](url)`, the URL made absolute; an unsafe scheme (javascript:, data:) leaves just the text. */
	link(text: string, url: string): string;
	/** A bulleted list; each item is markdown already (escape() plain text). */
	list(items: readonly string[]): string;
	/** A richtext value (a resolved prop's HTML or a stored Lexical state) as markdown. */
	richtext(value: unknown): string;
	/** `[title](url): description` for another page, linking its `.md` while markdown is on; null without a slug in this locale. */
	pageItem(page: Page): Promise<string | null>;
	/** This page's live, indexable children with a slug in the locale, by sort order and then newest first. */
	children(limit?: number): Promise<Page[]>;
};

/** `/about.md`, `/index.md` for the home page, under the locale prefix. */
export async function markdownPath(site: Site, path: string, locale: string): Promise<string> {
	const trimmed = path.replace(/^\/+|\/+$/g, "");
	return site.url(trimmed === "" ? "index.md" : `${trimmed}.md`, locale);
}

/** Markdown versions of pages: off until an editor turns them on; `markdown.enabled: false` turns them off for good. */
export async function markdownEnabled(site: Site): Promise<boolean> {
	return site.config.markdown?.enabled !== false && Boolean(await site.settings.get("seo.markdown.enabled", false));
}

/** Where an agent should read a page: its `.md` while markdown is on, else the page URL; null without a slug. */
export async function agentLink(site: Site, page: Page, locale: string, markdown?: boolean): Promise<string | null> {
	const path = await site.pages.slugs.pathFor(page.id, locale);
	if (path === null) {
		return null;
	}
	return site.absolute((markdown ?? (await markdownEnabled(site))) ? await markdownPath(site, path, locale) : await site.url(path, locale));
}

/** `[text](url)`, absolute; a URL the richtext renderer would refuse (javascript:, data:) leaves the text alone. */
export function markdownLink(site: Site, text: string, url: string): string {
	const label = escapeMarkdown(inline(text));
	return allowedUrl(url.trim()) ? `[${label}](${linkTarget(site.absolute(url.trim()))})` : label;
}

export function markdownContext(site: Site, page: Page, locale: string, defaultLocale: string, url: string, fields: Record<string, unknown>): MarkdownContext {
	const absolute = (target: string) => site.absolute(target);
	return {
		page,
		locale,
		defaultLocale,
		url,
		fields,
		site,
		text: plainText,
		escape: escapeMarkdown,
		heading: (text, level = 2) => heading(level, text),
		link: (text, target) => markdownLink(site, text, target),
		list,
		richtext: (value) => richtextMarkdown(value, absolute),
		pageItem: async (other) => {
			const target = await agentLink(site, other, locale);
			if (target === null) {
				return null;
			}
			const notes = inline(await ownDescription(site, other, locale));
			return `${markdownLink(site, await ownTitle(site, other, locale), target)}${notes !== "" ? `: ${escapeMarkdown(notes)}` : ""}`;
		},
		children: async (limit = 50) => {
			const rows = await whereLive(site.pages.query())
				.selectAll("tbtop_pages")
				.where("tbtop_pages.parent_id", "=", page.id)
				.where("tbtop_pages.is_indexable", "=", 1)
				.where("tbtop_pages.error_class", "is", null)
				.where("tbtop_pages.id", "in", (eb) => eb.selectFrom("tbtop_page_slugs").select("page_id").where("locale", "=", locale))
				.orderBy("tbtop_pages.sort_order")
				.orderBy("tbtop_pages.published_at", "desc")
				.orderBy("tbtop_pages.id", "desc")
				.limit(limit)
				.execute();
			return rows.map(pageFromRow);
		},
	};
}
