import { Renderer } from "@json-render/react";
import { Component, type CSSProperties, type FC, type ReactNode } from "react";
import { BLOCK_ERROR_KIND, type BlockErrorProps, type CatalogLike, markBrokenBlocks, type RenderSpec } from "./blockErrors";

type RegistryComponent = (props: { element: { type: string; props: unknown }; children?: ReactNode }) => ReactNode;
type Registry = Record<string, RegistryComponent>;

interface CmsRendererProps {
    spec: RenderSpec;
    catalog: CatalogLike;
    /** The `registry` from `defineRegistry()`; typed loosely so a host's exact registry type fits. */
    registry: Record<string, unknown>;
    /** Draw a placeholder naming the broken block; off, a broken block renders nothing. Never on for visitors. */
    showErrors: boolean;
}

const placeholderStyle: CSSProperties = {
    margin: "8px 0",
    padding: "12px 16px",
    border: "2px dashed #dc2626",
    background: "#fef2f2",
    color: "#991b1b",
    fontFamily: "ui-monospace, SFMono-Regular, Menlo, monospace",
    fontSize: "13px",
    whiteSpace: "pre-wrap",
};

export function BlockErrorPlaceholder({ kind, message, visible }: BlockErrorProps & { visible: boolean }) {
    if (!visible) {
        return null;
    }

    return (
        <div role="alert" data-testid="cms-block-error" data-kind={kind} style={placeholderStyle}>
            {message}
        </div>
    );
}

interface BoundaryProps {
    kind: string;
    showErrors: boolean;
    children: ReactNode;
}

/**
 * Catches a block component that throws while rendering. json-render's own boundary logs and renders
 * nothing; this one draws the same placeholder the other failure causes get. Only the client catches —
 * React's server renderer lets the throw escape, and Inertia then falls back to client-side rendering.
 */
class BlockBoundary extends Component<BoundaryProps, { error: Error | null }> {
    state = { error: null as Error | null };

    static getDerivedStateFromError(error: Error) {
        return { error };
    }

    componentDidCatch(error: Error) {
        console.error(`[tbtop-cms] Block [${this.props.kind}] threw while rendering:`, error);
    }

    render() {
        if (this.state.error) {
            return (
                <BlockErrorPlaceholder
                    kind={this.props.kind}
                    message={`Block [${this.props.kind}] threw while rendering: ${this.state.error.message}`}
                    visible={this.props.showErrors}
                />
            );
        }

        return this.props.children;
    }
}

const wrapped = new WeakMap<object, Map<boolean, Registry>>();

/** The registry with every block wrapped in a BlockBoundary and the placeholder kind added, memoised per registry. */
function guardedRegistry(registry: Record<string, unknown>, showErrors: boolean): Registry {
    const perRegistry = wrapped.get(registry) ?? new Map<boolean, Registry>();
    wrapped.set(registry, perRegistry);
    const cached = perRegistry.get(showErrors);
    if (cached) {
        return cached;
    }

    const guarded: Registry = {};
    for (const [kind, component] of Object.entries(registry as Registry)) {
        // Rendered as an element, not called: a throw must happen inside the boundary's subtree to be caught.
        const Block = component as FC<Parameters<RegistryComponent>[0]>;
        guarded[kind] = (props) => (
            <BlockBoundary kind={kind} showErrors={showErrors}>
                <Block {...props} />
            </BlockBoundary>
        );
    }
    guarded[BLOCK_ERROR_KIND] = ({ element }) => {
        const { kind, message } = element.props as BlockErrorProps;

        return <BlockErrorPlaceholder kind={kind} message={message} visible={showErrors} />;
    };
    perRegistry.set(showErrors, guarded);

    return guarded;
}

/**
 * `<Renderer>` for CMS pages and layout slots. A block that cannot render — the server failed to resolve it,
 * no component is registered for its kind, its props fail the catalog schema, or its component throws —
 * becomes a placeholder instead of vanishing: visible with `showErrors` (preview, app.debug), empty otherwise.
 */
export function CmsRenderer({ spec, catalog, registry, showErrors }: CmsRendererProps) {
    const guarded = guardedRegistry(registry, showErrors);

    return <Renderer spec={markBrokenBlocks(spec, catalog, registry)} registry={guarded} />;
}
