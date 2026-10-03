import type { Spec } from "@json-render/react";

/** The kind CmsRenderer draws in place of a block that cannot render. Not a block a host registers. */
export const BLOCK_ERROR_KIND = "CmsBlockError";

export interface BlockErrorProps {
    kind: string;
    message: string;
}

/** The server's `render` prop: a spec plus the blocks whose resolve() failed, by element id. */
export type RenderSpec = Spec & { errors?: Record<string, string> };

interface PropsSchema {
    safeParse(value: unknown): { success: boolean; error?: { issues: ReadonlyArray<{ path: PropertyKey[]; message: string }> } };
}

/** The slice of a `defineCatalog()` result CmsRenderer reads: `data.components[kind].props`, the zod schema per kind. */
export interface CatalogLike {
    data: { components?: Record<string, { props?: PropsSchema }> };
}

/**
 * Rewrites every element that cannot render into a `CmsBlockError` element carrying the reason, so the
 * page keeps the block's place and the placeholder — not an empty gap plus a console line — says what broke.
 * Three causes, checked in this order: the server failed to resolve the block, the kind has no component in
 * the registry, the props do not match the catalog's schema.
 */
export function markBrokenBlocks(spec: RenderSpec, catalog: CatalogLike, registry: Record<string, unknown>): Spec {
    const elements: Spec["elements"] = {};
    let changed = false;

    for (const [id, element] of Object.entries(spec.elements)) {
        const message = id === spec.root ? null : brokenReason(id, element.type, element.props, spec.errors, catalog, registry);
        if (message === null) {
            elements[id] = element;
            continue;
        }
        changed = true;
        elements[id] = { type: BLOCK_ERROR_KIND, props: { kind: element.type, message } satisfies BlockErrorProps };
    }

    return changed ? { ...spec, elements } : spec;
}

function brokenReason(
    id: string,
    kind: string,
    props: unknown,
    errors: Record<string, string> | undefined,
    catalog: CatalogLike,
    registry: Record<string, unknown>,
): string | null {
    if (errors?.[id]) {
        return errors[id];
    }
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
