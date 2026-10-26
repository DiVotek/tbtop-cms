// Client-safe: no server imports, so @tbtop/cms/react re-exports it.

export type ResolvedBlock = { id: string; kind: string; props: Record<string, unknown> };

/** A render spec json-render's Renderer takes: flat elements, `$root` lists the blocks in order. */
export type RenderSpec = {
	root: "$root";
	elements: Record<string, { type: string; props: Record<string, unknown>; children?: string[] }>;
};

/** PageSpecAssembler: blocks in order under the root; a duplicate or reserved id is skipped. */
export function assembleSpec(blocks: ResolvedBlock[], rootKind = "Fragment"): RenderSpec {
	const elements: RenderSpec["elements"] = {};
	const children: string[] = [];
	for (const block of blocks) {
		if (block.id === "$root" || block.id in elements) {
			console.warn("block.reserved_or_duplicate_id", { id: block.id, kind: block.kind });
			continue;
		}
		children.push(block.id);
		elements[block.id] = { type: block.kind, props: block.props };
	}
	elements.$root = { type: rootKind, props: {}, children };
	return { root: "$root", elements };
}
