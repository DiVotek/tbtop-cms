/**
 * Markdown building blocks for a page's `.md` version (Frontend/Markdown/Markdown.php),
 * so stored text reads as text: an editor's `*` or `[` stays a character,
 * and a line that starts with `#` or `1.` does not become a heading or a list.
 */

/** Characters with inline meaning, backslash-escaped. */
export const escapeMarkdown = (text: string): string => text.replace(/([\\`*_[\]<>~|])/gu, "\\$1");

/** A block marker at the start of any line (heading, quote, list item, setext underline), escaped. */
export const escapeLineStart = (text: string): string => text.replace(/^([ \t]*)([#>+\-=])/gmu, "$1\\$2").replace(/^([ \t]*\d+)([.)])/gmu, "$1\\$2");

/** Whitespace, newlines included, collapsed to single spaces. */
export const inline = (text: string): string => text.replace(/\s+/gu, " ").trim();

/** Plain text as one escaped paragraph per blank-line-separated chunk; single newlines stay line breaks. */
export function plainText(text: string | null | undefined): string {
	if (text === null || text === undefined) {
		return "";
	}
	return text
		.replaceAll("\r\n", "\n")
		.trim()
		.split(/\n\s*\n/u)
		.map((paragraph) =>
			escapeLineStart(
				paragraph
					.split("\n")
					.map((line) => escapeMarkdown(line.trim()))
					.filter((line) => line !== "")
					.join("  \n"),
			),
		)
		.filter((paragraph) => paragraph !== "")
		.join("\n\n");
}

/** `## text`, the level clamped to 1..6; '' for empty text. */
export function heading(level: number, text: string): string {
	const clean = inline(text);
	return clean === "" ? "" : `${"#".repeat(Math.max(1, Math.min(6, Math.trunc(level))))} ${escapeMarkdown(clean)}`;
}

/** The characters that would end a markdown link target, percent-encoded. */
export function linkTarget(url: string): string {
	const codes: Record<string, string> = { " ": "%20", "(": "%28", ")": "%29", "<": "%3C", ">": "%3E", "\t": "%09", "\n": "%0A", "\r": "%0D" };
	return url.replace(/[ ()<>\t\n\r]/g, (char) => codes[char] ?? char);
}

/** A bulleted list; items are markdown already, empty ones dropped. */
export function list(items: readonly string[]): string {
	return items
		.map(inline)
		.filter((item) => item !== "")
		.map((item) => `- ${item}`)
		.join("\n");
}

/** Every line prefixed with `> `. */
export function quote(markdown: string): string {
	const trimmed = markdown.trim();
	return trimmed === "" ? "" : trimmed.replace(/^/gmu, "> ");
}

const longestRun = (text: string): number => Math.max(0, ...[...text.matchAll(/`+/g)].map((run) => run[0].length));

/** A fenced code block whose fence is longer than any backtick run inside. */
export function fence(text: string, info = ""): string {
	const ticks = "`".repeat(Math.max(3, longestRun(text) + 1));
	return `${ticks}${info}\n${text.replace(/\n+$/, "")}\n${ticks}`;
}

/** Inline code, delimited past any backtick run inside. */
export function code(text: string): string {
	const ticks = "`".repeat(longestRun(text) + 1);
	const pad = text.startsWith("`") || text.endsWith("`") ? " " : "";
	return `${ticks}${pad}${text}${pad}${ticks}`;
}
