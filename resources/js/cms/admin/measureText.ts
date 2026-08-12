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
