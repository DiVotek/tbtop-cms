import type { ResolvedBlock } from "../../blocks/spec";
import { translation, translationText } from "../../content/page";
import { isExternal } from "../../content/redirects";
import { externalLocation, headerSafe, pageProps, resolvePage, routeLocale } from "../../frontend/load";
import type { Site } from "../../site";
import { contactLines, notesBlock, perType, siteName } from "../llmsTxt";
import { localizedWithFallback } from "../localized";
import { indexingEnabled, type Seo } from "../resolver";
import { contentSignalValue } from "../robots";
import { type MarkdownContext, markdownContext, markdownEnabled } from "./context";
import { markdownString } from "./strings";
import { fence, heading, plainText } from "./text";

export const MARKDOWN_CONTENT_TYPE = "text/markdown; charset=utf-8";

/** MarkdownRequest::suffixPath: the page path without its `.md` (`index.md` is the home page), or null. */
export function suffixPath(path: string): string | null {
	const trimmed = path.replace(/^\/+|\/+$/g, "");
	if (!trimmed.endsWith(".md") || trimmed === ".md") {
		return null;
	}
	const page = trimmed.slice(0, -3);
	return page === "index" ? "" : page;
}

/**
 * MarkdownRequest::accepts: `Accept` ranks `text/markdown` above `text/html`
 * (a tie goes to the type listed first; `*\/*` counts for HTML only). An
 * Inertia visit is never markdown.
 */
export function acceptsMarkdown(request: Request): boolean {
	if (request.headers.has("X-Inertia")) {
		return false;
	}
	let markdown: { q: number; index: number } | null = null;
	let html: { q: number; index: number } | null = null;
	(request.headers.get("Accept") ?? "").split(",").forEach((part, index) => {
		const [type = "", ...params] = part.split(";").map((piece) => piece.trim().toLowerCase());
		const qParam = params.find((param) => param.startsWith("q="));
		const parsed = qParam === undefined ? 1 : Number(qParam.slice(2));
		const q = Number.isFinite(parsed) ? Math.min(1, Math.max(0, parsed)) : 1;
		if (type === "text/markdown") {
			markdown ??= { q, index };
		} else if (["text/html", "text/*", "*/*"].includes(type) && (html === null || q > html.q)) {
			html = { q, index };
		}
	});
	const md = markdown as { q: number; index: number } | null;
	const best = html as { q: number; index: number } | null;
	if (md === null || md.q <= 0) {
		return false;
	}
	return best === null || md.q > best.q || (md.q === best.q && md.index < best.index);
}

/** Whether to look at the request at all: no settings read for an ordinary page view. */
export function mightWantMarkdown(request: Request): boolean {
	return new URL(request.url).pathname.endsWith(".md") || (request.headers.get("Accept") ?? "").toLowerCase().includes("text/markdown");
}

/**
 * The page as markdown (PageController::markdownResponse), or null to leave
 * the request to the host: markdown off, not asked for, or no live page there
 * (the host's 404 then answers it). Drafts are never served.
 */
export async function markdownResponse(site: Site, request: Request): Promise<Response | null> {
	if (!mightWantMarkdown(request) || !(await markdownEnabled(site)) || !(await indexingEnabled(site))) {
		return null;
	}
	const url = new URL(request.url);
	const route = await routeLocale(site, url);
	if (route.type === "redirect") {
		return route.location.endsWith(".md") ? new Response(null, { status: 301, headers: { location: route.location } }) : null;
	}
	const stripped = suffixPath(route.path);
	if (stripped === null && !acceptsMarkdown(request)) {
		return null;
	}
	const path = stripped ?? route.path;
	const page = await resolvePage(site, path, route.locale, false);
	if (page === null) {
		return redirectFor(site, path, route.locale);
	}
	const props = await pageProps(site, page, route.locale, path);
	const defaultLocale = await site.languages.defaultLocale();
	const ctx = markdownContext(site, page, route.locale, defaultLocale, props.seo.canonical, props.page.fields);
	const headers: Record<string, string> = {
		"Content-Type": MARKDOWN_CONTENT_TYPE,
		Vary: "Accept",
		// The HTML version is the one to index and cite.
		Link: `<${props.seo.canonical}>; rel="canonical"`,
	};
	if (props.seo.noindex) {
		headers["X-Robots-Tag"] = "noindex";
	}
	const signals = await contentSignalValue(site);
	if (signals !== null) {
		headers["Content-Signal"] = signals;
	}
	return new Response(await pageMarkdown(ctx, orderedBlocks(props.render), props.seo), { headers });
}

/** A redirect for a missing page, as the HTML path has; null leaves the 404 to the host. */
async function redirectFor(site: Site, path: string, locale: string): Promise<Response | null> {
	const redirect = await site.redirects.resolve(path, locale);
	if (redirect === null) {
		return null;
	}
	const location = isExternal(redirect.target) ? externalLocation(redirect.target, site.origin) : headerSafe(await site.url(redirect.target, locale));
	return new Response(null, { status: redirect.status, headers: { location } });
}

/** The blocks in render order, from the spec loadPage builds. */
function orderedBlocks(render: { elements: Record<string, { type: string; props: Record<string, unknown>; children?: string[] }> }): ResolvedBlock[] {
	const ids = render.elements.$root?.children ?? [];
	return ids.flatMap((id) => {
		const element = render.elements[id];
		return element === undefined ? [] : [{ id, kind: element.type, props: element.props }];
	});
}

/**
 * PageMarkdown::render: frontmatter, `# heading`, summary, body, every block,
 * the page type's part, `## About {site}` (notes for AI and contacts), and the
 * page's JSON-LD graph as a `json` block.
 */
export async function pageMarkdown(ctx: MarkdownContext, blocks: ResolvedBlock[], seo: Seo): Promise<string> {
	const { page, locale, defaultLocale } = ctx;
	const own = translationText(page, "heading", locale, defaultLocale);
	const parts = [
		frontmatter(ctx, seo),
		heading(1, own !== null && own.trim() !== "" ? own : seo.ownTitle),
		plainText(translationText(page, "summary", locale, defaultLocale)),
		ctx.richtext(translation(page, "body", locale, defaultLocale)),
	];
	for (const block of blocks) {
		parts.push(await blockMarkdown(ctx, block));
	}
	parts.push(await typeMarkdown(ctx));
	parts.push(await about(ctx));
	if (seo.jsonLd.length > 0) {
		parts.push(fence(JSON.stringify(seo.jsonLd, null, 4), "json"));
	}
	return `${parts.filter((part) => part.trim() !== "").join("\n\n")}\n`;
}

const yaml = (value: string): string => JSON.stringify(value);

function frontmatter(ctx: MarkdownContext, seo: Seo): string {
	const lines = ["---"];
	const fields: [string, string | null][] = [
		["title", seo.title],
		["description", seo.description],
		["url", ctx.url],
		["lang", ctx.locale],
		["image", seo.ogImage],
	];
	for (const [key, value] of fields) {
		if (value !== null && value !== "") {
			lines.push(`${key}: ${yaml(value)}`);
		}
	}
	const alternates = seo.alternates.filter((row) => row.locale !== ctx.locale);
	if (alternates.length > 0) {
		lines.push("alternates:", ...alternates.map((row) => `  ${yaml(row.locale)}: ${yaml(row.url)}`));
	}
	lines.push("---");
	return lines.join("\n");
}

/** BlockMarkdown: the block's own toMarkdown, else its translatable text; a throw skips it unless strictBlocks. */
async function blockMarkdown(ctx: MarkdownContext, block: ResolvedBlock): Promise<string> {
	if (!ctx.site.blocks.has(block.kind)) {
		return "";
	}
	const kind = ctx.site.blocks.get(block.kind);
	try {
		const own = kind.toMarkdown === null ? null : await kind.toMarkdown(block.props, ctx);
		return (own ?? fallback(block.props, new Set(kind.translatableFields()), "", ctx)).trim();
	} catch (error) {
		if (ctx.site.config.strictBlocks === true) {
			throw error;
		}
		console.warn("block.to_markdown_failed", { kind: block.kind, id: block.id, error: String(error) });
		return "";
	}
}

const HTML = /^\s*<[a-z][a-z0-9]*[\s/>]/i;

/** The strings at translatable paths, in props order; list indices are not part of a path, so rows come out row by row. */
function fallback(value: unknown, paths: Set<string>, path: string, ctx: MarkdownContext): string {
	if (typeof value === "string") {
		if (!paths.has(path)) {
			return "";
		}
		return HTML.test(value) ? ctx.richtext(value) : plainText(value);
	}
	if (value === null || typeof value !== "object") {
		return "";
	}
	const entries: [string, unknown][] = Array.isArray(value) ? value.map((item) => [path, item]) : Object.entries(value).map(([key, item]) => [path === "" ? key : `${path}.${key}`, item]);
	return entries
		.map(([childPath, item]) => fallback(item, paths, childPath, ctx))
		.filter((part) => part !== "")
		.join("\n\n");
}

/** The subtype's toMarkdown, else the base's, else the children list for a type that allows children. */
async function typeMarkdown(ctx: MarkdownContext): Promise<string> {
	const { site, page } = ctx;
	const type = page.subtype !== null && site.pageTypes.has(page.subtype) ? site.pageTypes.get(page.subtype) : site.pageTypes.has(page.type) ? site.pageTypes.get(page.type) : null;
	if (type === null) {
		return "";
	}
	const toMarkdown = type.toMarkdown;
	// Like a block's: a throw leaves the part out with a warning, unless strictBlocks.
	try {
		return toMarkdown === null ? await defaultTypeMarkdown(ctx, type.allowedChildren.length > 0) : ((await toMarkdown(page, ctx)) ?? "").trim();
	} catch (error) {
		if (site.config.strictBlocks === true) {
			throw error;
		}
		console.warn("page_type.to_markdown_failed", { type: type.key, page: page.id, error: String(error) });
		return "";
	}
}

/** BasePageType::toMarkdown: `## In this section` with the live, indexable children, up to the llms.txt per-type limit. */
export async function defaultTypeMarkdown(ctx: MarkdownContext, allowsChildren: boolean): Promise<string> {
	if (!allowsChildren) {
		return "";
	}
	const items: string[] = [];
	for (const child of await ctx.children(await perType(ctx.site))) {
		const item = await ctx.pageItem(child);
		if (item !== null) {
			items.push(item);
		}
	}
	return items.length === 0 ? "" : `${ctx.heading(markdownString("children", ctx.locale))}\n\n${ctx.list(items)}`;
}

/** `## About {site}`: the notes for AI and the llms.txt contact lines; '' when both are empty. */
async function about(ctx: MarkdownContext): Promise<string> {
	const { site, locale, defaultLocale } = ctx;
	const intro = notesBlock(localizedWithFallback(await site.settings.get("seo.llms_txt.intro"), locale, defaultLocale));
	const contacts = (await contactLines(site, locale, defaultLocale)).join("\n");
	const body = [intro, contacts].filter((part) => part !== "").join("\n\n");
	if (body === "") {
		return "";
	}
	return `${ctx.heading(markdownString("about", locale, { site: await siteName(site) }))}\n\n${body}`;
}
