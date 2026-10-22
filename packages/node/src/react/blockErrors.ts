import type { Spec } from "@json-render/react";

/** The server's `render` prop: `{root, elements}`. A row that cannot render never reaches it — the server skips it. */
export type RenderSpec = Spec;

interface PropsSchema {
	safeParse(value: unknown): { success: boolean; error?: { issues: ReadonlyArray<{ path: PropertyKey[]; message: string }> } };
}

/** The slice of a `defineCatalog()` result CmsRenderer reads: `data.components[kind].props`, the zod schema per kind. */
export interface CatalogLike {
	data: { components?: Record<string, { props?: PropsSchema }> };
}

export interface DroppedBlock {
	id: string;
	kind: string;
	message: string;
}

/** A spec the renderer can walk. A server-side fallback that sends `[]` (or nothing) is an empty page, not a crash. */
export function hasElements(spec: unknown): spec is RenderSpec {
	return typeof spec === "object" && spec !== null && "elements" in spec && typeof spec.elements === "object" && spec.elements !== null;
}

/**
 * Removes every element the client cannot draw — its kind has no component in the registry, or its props fail
 * the catalog's schema — together with its id in any `children` list. Both are developer bugs (a component
 * not registered, a `resolve()` out of step with its zod schema), so the page renders without the block and
 * the caller decides whether to say so. The spec comes back as the same object when nothing was dropped.
 */
export function dropBrokenBlocks(spec: RenderSpec, catalog: CatalogLike, registry: Record<string, unknown>): { spec: Spec; dropped: DroppedBlock[] } {
	const dropped: DroppedBlock[] = [];
	if (!hasElements(spec)) {
		return { spec, dropped };
	}
	for (const [id, element] of Object.entries(spec.elements)) {
		const message = id === spec.root ? null : brokenReason(element.type, element.props, catalog, registry);
		if (message !== null) {
			dropped.push({ id, kind: element.type, message });
		}
	}
	if (dropped.length === 0) {
		return { spec, dropped };
	}

	const gone = new Set(dropped.map((block) => block.id));
	const elements: Spec["elements"] = {};
	for (const [id, element] of Object.entries(spec.elements)) {
		if (gone.has(id)) {
			continue;
		}
		elements[id] = element.children ? { ...element, children: element.children.filter((child) => !gone.has(child)) } : element;
	}

	return { spec: { ...spec, elements }, dropped };
}

function brokenReason(kind: string, props: unknown, catalog: CatalogLike, registry: Record<string, unknown>): string | null {
	// Own keys only: `constructor` or `toString` stored as a kind must not pass as a component.
	if (!Object.hasOwn(registry, kind)) {
		return `Block [${kind}] has no component in the registry.`;
	}
	const schema = catalog.data.components?.[kind]?.props;
	if (!schema) {
		return null;
	}
	const result = schema.safeParse(props);
	if (result.success) {
		return null;
	}
	const issues = (result.error?.issues ?? []).slice(0, 3).map((issue) => `${issue.path.join(".") || "props"}: ${issue.message}`);

	return `Block [${kind}] props do not match its schema — ${issues.join("; ")}`;
}
