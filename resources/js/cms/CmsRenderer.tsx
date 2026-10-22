import { Renderer } from "@json-render/react";
import { Component, type FC, type ReactNode } from "react";
import { type CatalogLike, dropBrokenBlocks, hasElements, type RenderSpec } from "./blockErrors";

type RegistryComponent = (props: { element: { type: string; props: unknown }; children?: ReactNode }) => ReactNode;
type Registry = Record<string, RegistryComponent>;

interface CmsRendererProps {
    spec: RenderSpec;
    catalog: CatalogLike;
    /** The `registry` from `defineRegistry()`; typed loosely so a host's exact registry type fits. */
    registry: Record<string, unknown>;
    /**
     * Gates the developer console line for a block the client could not draw (no component, props failing the
     * schema, a throwing component). The page looks the same either way: such a block renders nothing.
     */
    showErrors: boolean;
}

const reported = new Set<string>();

/** In the browser once per page load per message — a re-render must not repeat it. The server pass logs each time. */
function report(message: string, error?: unknown) {
    if (typeof window !== "undefined") {
        if (reported.has(message)) {
            return;
        }
        reported.add(message);
    }
    if (error === undefined) {
        console.error(`[tbtop-cms] ${message}`);
    } else {
        console.error(`[tbtop-cms] ${message}`, error);
    }
}

interface BoundaryProps {
    kind: string;
    showErrors: boolean;
    children: ReactNode;
}

/**
 * Catches a block component that throws while rendering on the client and renders nothing in its place, so
 * one broken block does not take the page down. React's server renderer does not run boundaries, so the SSR
 * path is guardServerRender() instead.
 */
class BlockBoundary extends Component<BoundaryProps, { failed: boolean }> {
    state = { failed: false };

    static getDerivedStateFromError() {
        return { failed: true };
    }

    componentDidCatch(error: Error) {
        if (this.props.showErrors) {
            report(`Block [${this.props.kind}] threw while rendering.`, error);
        }
    }

    render() {
        return this.state.failed ? null : this.props.children;
    }
}

/**
 * The server has no error boundaries, and a throw there aborts the whole SSR response. A registry entry
 * from defineRegistry() already calls the host's component as a plain function inside its own render, so
 * on the server that call is wrapped in try/catch — one-shot rendering makes a hook-order change after a
 * throw harmless there, which is exactly why the client cannot do the same and uses BlockBoundary. Only
 * the block's own render body is covered: a throw in a component it nests still escapes the server pass,
 * and Inertia then falls back to client-side rendering, where the boundary catches it.
 */
function guardServerRender(kind: string, component: RegistryComponent, showErrors: boolean): RegistryComponent {
    return (props) => {
        try {
            return component(props);
        } catch (error) {
            if (showErrors) {
                report(`Block [${kind}] threw while rendering.`, error);
            }

            return null;
        }
    };
}

const wrapped = new WeakMap<object, Map<boolean, Registry>>();

/** The registry with every block guarded, memoised per registry. The server/client choice is made here, so one bundle serves both passes. */
function guardedRegistry(registry: Record<string, unknown>, showErrors: boolean): Registry {
    const perRegistry = wrapped.get(registry) ?? new Map<boolean, Registry>();
    wrapped.set(registry, perRegistry);
    const cached = perRegistry.get(showErrors);
    if (cached) {
        return cached;
    }

    const guarded: Registry = {};
    for (const [kind, component] of Object.entries(registry as Registry)) {
        if (typeof window === "undefined") {
            guarded[kind] = guardServerRender(kind, component, showErrors);
            continue;
        }
        // Rendered as an element, not called: a throw must happen inside the boundary's subtree to be caught.
        const Block = component as FC<Parameters<RegistryComponent>[0]>;
        guarded[kind] = (props) => (
            <BlockBoundary kind={kind} showErrors={showErrors}>
                <Block {...props} />
            </BlockBoundary>
        );
    }
    perRegistry.set(showErrors, guarded);

    return guarded;
}

/**
 * `<Renderer>` for CMS pages and layout slots. A row the editor has to fix (unregistered kind, missing shared
 * block) never reaches the client — the server skips it and the admin lists it. What this guards against are
 * developer bugs: a kind with no component, props failing the catalog schema, a component that throws. Each
 * renders nothing; with `showErrors` (preview, `app.debug`) it also leaves a console line.
 */
export function CmsRenderer({ spec, catalog, registry, showErrors }: CmsRendererProps) {
    if (!hasElements(spec)) {
        return null;
    }
    const guarded = guardedRegistry(registry, showErrors);
    const { spec: drawable, dropped } = dropBrokenBlocks(spec, catalog, registry);
    if (showErrors) {
        for (const block of dropped) {
            report(block.message);
        }
    }

    return <Renderer spec={drawable} registry={guarded} />;
}
