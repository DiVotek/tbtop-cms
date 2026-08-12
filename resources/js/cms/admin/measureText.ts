/**
 * Measures rendered text width in CSS pixels via a 2D canvas — the same way
 * Google's own result column truncates, which character counts cannot
 * approximate (a "W" is roughly three times an "i").
 *
 * The canvas is created once and reused: allocating one per keystroke would
 * leak measurement contexts on every live-region refresh.
 */
let context: CanvasRenderingContext2D | null = null;

function measuringContext(): CanvasRenderingContext2D | null {
	if (context) {
		return context;
	}
	// SSR and the test DOM have no canvas; callers treat null as "unknown".
	if (typeof document === "undefined") {
		return null;
	}
	context = document.createElement("canvas").getContext("2d");
	return context;
}

export function measureTextWidth(text: string, font: string): number | null {
	const ctx = measuringContext();
	if (!ctx) {
		return null;
	}
	ctx.font = font;
	return ctx.measureText(text).width;
}

export interface TextLayout {
	/** Width of the widest line — what decides whether the column overflows. */
	width: number;
	lines: number;
}

/**
 * Lays text out over a column the way a browser does — greedy word wrapping,
 * breaking mid-word only when a single word is wider than the column.
 *
 * A title is judged on how it wraps, not on its unwrapped length: two lines of
 * 460px do not hold every 920px title, because each break leaves the rest of
 * that line unused, and one unbreakable word can overflow a column while the
 * total still looks comfortable.
 */
export function layOutText(text: string, font: string, columnWidth: number): TextLayout | null {
	const ctx = measuringContext();
	if (!ctx) {
		return null;
	}
	ctx.font = font;

	const lines: string[] = [];
	let current = "";

	for (const word of text.split(/\s+/).filter(Boolean)) {
		const candidate = current ? `${current} ${word}` : word;

		if (ctx.measureText(candidate).width <= columnWidth) {
			current = candidate;
			continue;
		}

		if (current) {
			lines.push(current);
			current = "";
		}

		// A word too wide to ever fit is broken across lines rather than left
		// to overflow, matching the card's overflow-wrap behaviour.
		let rest = word;
		while (ctx.measureText(rest).width > columnWidth) {
			let cut = 1;
			while (cut < rest.length && ctx.measureText(rest.slice(0, cut + 1)).width <= columnWidth) {
				cut++;
			}
			lines.push(rest.slice(0, cut));
			rest = rest.slice(cut);
		}
		current = rest;
	}

	if (current) {
		lines.push(current);
	}

	const width = lines.reduce((widest, line) => Math.max(widest, ctx.measureText(line).width), 0);

	return { width, lines: Math.max(1, lines.length) };
}
