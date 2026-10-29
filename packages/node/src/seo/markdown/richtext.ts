import { allowedUrl, MAX_DEPTH, MAX_NODES, normalize } from "../../content/richtext";
import { type Dict, isDict } from "../../support/values";
import { code, escapeLineStart, escapeMarkdown, fence, inline, linkTarget, quote } from "./text";

/** Lexical format bits with their markdown markers, innermost first (code is handled apart). */
const FORMATS: [number, string][] = [
	[1, "**"],
	[2, "_"],
	[4, "~~"],
];

const HTML = /^\s*<[a-z][a-z0-9]*[\s/>]/i;

/**
 * RichtextMarkdownRenderer: a stored richtext value, or a resolved prop's
 * HTML, as markdown. Headings start at H2 (the page heading is the H1),
 * links keep only the URLs the HTML renderer allows, made absolute.
 */
export function richtextMarkdown(value: unknown, absolute: (url: string) => string): string {
	const state = typeof value === "string" && HTML.test(value) ? htmlToState(value) : normalize(value);
	const root = state?.root;
	if (!isDict(root)) {
		return "";
	}
	return new Renderer(absolute).blocks(root, 0).trim();
}

class Renderer {
	private nodes = 0;

	constructor(private readonly absolute: (url: string) => string) {}

	blocks(node: Dict, depth: number): string {
		return this.children(node, depth)
			.map((child) => this.block(child, depth + 1))
			.filter((part) => part.trim() !== "")
			.join("\n\n");
	}

	private block(node: Dict, depth: number): string {
		switch (node.type) {
			case "paragraph":
				return escapeLineStart(this.inline(node, depth));
			case "heading": {
				const match = typeof node.tag === "string" ? /^h([1-6])$/.exec(node.tag) : null;
				const text = inline(this.inline(node, depth));
				if (text === "") {
					return "";
				}
				return match === null ? escapeLineStart(text) : `${"#".repeat(Math.max(2, Number(match[1])))} ${text}`;
			}
			case "quote":
				return quote(this.inline(node, depth));
			case "list":
				return this.list(node, depth, 0);
			case "code":
				return fence(
					this.children(node, depth)
						.map((child) => (child.type === "linebreak" ? "\n" : typeof child.text === "string" ? child.text : ""))
						.join(""),
				);
			default:
				return this.node(node, depth);
		}
	}

	private list(node: Dict, depth: number, indent: number): string {
		const ordered = node.listType === "number";
		let number = Number.isInteger(node.start) && (node.start as number) > 0 ? (node.start as number) : 1;
		const lines: string[] = [];
		for (const item of this.children(node, depth)) {
			let text = "";
			const nested: string[] = [];
			for (const child of this.children(item, depth + 1)) {
				if (child.type === "list") {
					nested.push(...this.list(child, depth + 2, indent + (ordered ? 3 : 2)).split("\n"));
				} else {
					text += this.node(child, depth + 2);
				}
			}
			const clean = escapeLineStart(inline(text));
			if (clean !== "") {
				lines.push(`${" ".repeat(indent)}${ordered ? `${number++}. ` : "- "}${clean}`);
			}
			lines.push(...nested.filter((line) => line.trim() !== ""));
		}
		return lines.join("\n");
	}

	private inline(node: Dict, depth: number): string {
		return this.children(node, depth)
			.map((child) => this.node(child, depth + 1))
			.join("");
	}

	private node(node: Dict, depth: number): string {
		switch (node.type) {
			case "text":
				return text(node);
			case "linebreak":
				return "  \n";
			case "link":
			case "autolink":
				return this.link(node, this.inline(node, depth));
			default:
				return this.inline(node, depth);
		}
	}

	private link(node: Dict, inner: string): string {
		const url = typeof node.url === "string" ? node.url.trim() : "";
		if (url === "" || !allowedUrl(url) || inner.trim() === "") {
			return inner;
		}
		return `[${inner}](${linkTarget(this.absolute(url))})`;
	}

	/** The node's dict children, within the depth and node limits of the HTML renderer. */
	private children(node: Dict, depth: number): Dict[] {
		if (depth >= MAX_DEPTH || !Array.isArray(node.children)) {
			return [];
		}
		const out: Dict[] = [];
		for (const child of node.children) {
			if (isDict(child) && this.nodes < MAX_NODES) {
				this.nodes++;
				out.push(child);
			}
		}
		return out;
	}
}

function text(node: Dict): string {
	const value = typeof node.text === "string" ? node.text : "";
	const format = Number.isInteger(node.format) ? (node.format as number) : 0;
	if (value.trim() === "") {
		return value;
	}
	if ((format & 16) !== 0) {
		return code(value);
	}
	// Markers hug the words: `** bold **` is not bold in markdown.
	const [, lead = "", core = "", trail = ""] = /^(\s*)([\s\S]*?)(\s*)$/u.exec(value) ?? [];
	let out = escapeMarkdown(core);
	for (const [bit, marker] of FORMATS) {
		if ((format & bit) !== 0) {
			out = `${marker}${out}${marker}`;
		}
	}
	return `${lead}${out}${trail}`;
}

const BLOCK_TAGS: Record<string, string> = { p: "paragraph", blockquote: "quote", li: "listitem", pre: "code" };
const FORMAT_TAGS: Record<string, number> = { strong: 1, b: 1, em: 2, i: 2, s: 4, u: 8, code: 16 };
const DROPPED = new Set(["script", "style", "template", "iframe", "noscript", "svg", "math", "textarea", "select", "head", "title"]);
const ENTITIES: Record<string, string> = { amp: "&", lt: "<", gt: ">", quot: '"', apos: "'", nbsp: " " };

const decode = (text: string): string =>
	text.replace(/&(#x[0-9a-f]+|#\d+|[a-z]+);/gi, (whole, ref: string) => {
		if (ref[0] === "#") {
			const point = ref[1]?.toLowerCase() === "x" ? Number.parseInt(ref.slice(2), 16) : Number.parseInt(ref.slice(1), 10);
			return Number.isFinite(point) && point > 0 && point <= 0x10ffff ? String.fromCodePoint(point) : whole;
		}
		return ENTITIES[ref.toLowerCase()] ?? whole;
	});

/**
 * HTML to Lexical-shaped nodes for the tags the HTML renderer writes (a
 * resolved richtext prop): blocks, lists, links, formats and `<br>`. Any
 * other tag is transparent; script-like elements are dropped with their content.
 */
export function htmlToState(html: string): Dict {
	const root: Dict = { type: "root", children: [] };
	const stack: { tag: string; node: Dict; format: number }[] = [{ tag: "", node: root, format: 0 }];
	let dropping: string | null = null;
	for (const match of html.matchAll(/<(\/?)([a-z][a-z0-9]*)([^>]*)>|([^<]+)/gi)) {
		const [, closing, rawTag, attrs, content] = match;
		const top = stack[stack.length - 1] as { tag: string; node: Dict; format: number };
		if (content !== undefined) {
			if (dropping === null) {
				(top.node.children as Dict[]).push({ type: "text", text: decode(content), format: top.format });
			}
			continue;
		}
		const tag = (rawTag ?? "").toLowerCase();
		if (dropping !== null) {
			if (closing === "/" && tag === dropping) {
				dropping = null;
			}
			continue;
		}
		if (closing !== "/" && DROPPED.has(tag)) {
			dropping = tag;
			continue;
		}
		if (closing === "/") {
			const at = stack.findLastIndex((entry) => entry.tag === tag);
			if (at > 0) {
				stack.length = at;
			}
			continue;
		}
		if (tag === "br") {
			(top.node.children as Dict[]).push({ type: "linebreak" });
			continue;
		}
		const node = element(tag, attrs ?? "");
		if (node === null) {
			const bit = FORMAT_TAGS[tag];
			if (bit !== undefined) {
				stack.push({ tag, node: top.node, format: top.format | bit });
			}
			continue;
		}
		(top.node.children as Dict[]).push(node);
		stack.push({ tag, node, format: top.format });
	}
	return { root };
}

function element(tag: string, attrs: string): Dict | null {
	if (/^h[1-6]$/.test(tag)) {
		return { type: "heading", tag, children: [] };
	}
	if (tag === "ul" || tag === "ol") {
		const start = /\bstart="(\d+)"/.exec(attrs);
		return { type: "list", listType: tag === "ol" ? "number" : "bullet", ...(start === null ? {} : { start: Number(start[1]) }), children: [] };
	}
	if (tag === "a") {
		const href = /\bhref="([^"]*)"/.exec(attrs);
		return { type: "link", url: decode(href?.[1] ?? ""), children: [] };
	}
	const type = BLOCK_TAGS[tag];
	return type === undefined ? null : { type, children: [] };
}
