import { type Dict, isDict } from "../support/values";

const MAX_DEPTH = 64;
const MAX_NODES = 10000;
const ALIGNMENTS = new Set(["center", "right", "justify"]);
const FORMAT_TAGS: [number, string][] = [
	[1, "strong"],
	[2, "em"],
	[16, "code"],
	[8, "u"],
	[4, "s"],
	[32, "sub"],
	[64, "sup"],
];
const LINK_SCHEMES = new Set(["http", "https", "mailto", "tel"]);
const LINK_TARGETS = new Set(["_blank", "_self"]);

/** htmlspecialchars($text, ENT_QUOTES). */
export const escapeHtml = (text: string): string =>
	text.replaceAll("&", "&amp;").replaceAll("<", "&lt;").replaceAll(">", "&gt;").replaceAll('"', "&quot;").replaceAll("'", "&#039;");

/**
 * Lexical JSON (an object, or its JSON text) to HTML, the PHP renderer's
 * allowlist: paragraph, heading, quote, list, listitem, code, linebreak,
 * text format bits, and links with safe schemes. Plain text becomes one
 * paragraph. Legacy HTML strings are escaped, not parsed: converting them
 * to nodes (RichtextHtmlToNodes) is not ported yet.
 */
export function richtextHtml(value: unknown): string {
	const state = normalize(value);
	const root = state?.root;
	if (!isDict(root)) {
		return "";
	}
	const budget = { nodes: 0 };
	return children(root, 0, budget);
}

function normalize(value: unknown): Dict | null {
	if (isDict(value) && "root" in value) {
		return value;
	}
	if (typeof value !== "string") {
		return null;
	}
	try {
		const decoded: unknown = JSON.parse(value);
		if (isDict(decoded) && "root" in decoded) {
			return decoded;
		}
	} catch {
		// not JSON: plain text below
	}
	if (value.trim() === "") {
		return null;
	}
	return { root: { children: [{ type: "paragraph", children: [{ type: "text", text: value, format: 0 }] }] } };
}

function children(node: Dict, depth: number, budget: { nodes: number }): string {
	if (depth >= MAX_DEPTH || !Array.isArray(node.children)) {
		return "";
	}
	let html = "";
	for (const child of node.children) {
		if (!isDict(child)) {
			continue;
		}
		if (budget.nodes >= MAX_NODES) {
			break;
		}
		budget.nodes++;
		html += render(child, depth + 1, budget);
	}
	return html;
}

function render(node: Dict, depth: number, budget: { nodes: number }): string {
	const inner = () => children(node, depth, budget);
	switch (node.type) {
		case "paragraph":
			return wrap("p", node, inner());
		case "heading": {
			const tag = typeof node.tag === "string" && /^h[1-6]$/.test(node.tag) ? node.tag : "p";
			return wrap(tag, node, inner());
		}
		case "quote":
			return wrap("blockquote", node, inner());
		case "list": {
			const ordered = node.listType === "number";
			const tag = ordered ? "ol" : "ul";
			const start = ordered && Number.isInteger(node.start) && (node.start as number) > 1 ? ` start="${node.start}"` : "";
			return `<${tag}${start}>${inner()}</${tag}>`;
		}
		case "listitem":
			return wrap("li", node, inner());
		case "code":
			return `<pre><code>${inner()}</code></pre>`;
		case "linebreak":
			return "<br>";
		case "text":
			return text(node);
		case "link":
		case "autolink":
			return link(node, inner());
		default:
			return inner();
	}
}

function wrap(tag: string, node: Dict, inner: string): string {
	const align = typeof node.format === "string" && ALIGNMENTS.has(node.format) ? ` style="text-align:${node.format}"` : "";
	return `<${tag}${align}>${inner}</${tag}>`;
}

function text(node: Dict): string {
	let html = escapeHtml(typeof node.text === "string" ? node.text : "");
	const format = Number.isInteger(node.format) ? (node.format as number) : 0;
	for (const [bit, tag] of FORMAT_TAGS) {
		if ((format & bit) !== 0) {
			html = `<${tag}>${html}</${tag}>`;
		}
	}
	return html;
}

function link(node: Dict, inner: string): string {
	const url = typeof node.url === "string" ? node.url : "";
	if (!allowedUrl(url)) {
		return inner;
	}
	let attrs = `href="${escapeHtml(url)}"`;
	if (typeof node.title === "string" && node.title !== "") {
		attrs += ` title="${escapeHtml(node.title)}"`;
	}
	if (typeof node.target === "string" && LINK_TARGETS.has(node.target)) {
		attrs += ` target="${node.target}"`;
		if (node.target === "_blank") {
			attrs += ' rel="noopener noreferrer"';
		}
	}
	return `<a ${attrs}>${inner}</a>`;
}

function allowedUrl(raw: string): boolean {
	const url = raw.replace(/[\t\r\n]/g, "").replace(/^[\x00-\x20]+|[\x00-\x20]+$/g, "");
	if (url === "") {
		return false;
	}
	if (url[0] === "/" || url[0] === "#" || url[0] === "?") {
		return true;
	}
	const scheme = /^([a-z][a-z0-9+.-]*):/i.exec(url);
	return scheme === null || LINK_SCHEMES.has((scheme[1] ?? "").toLowerCase());
}
