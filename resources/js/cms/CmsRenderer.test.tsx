import { render } from "@testing-library/react";
import { JSONUIProvider, type Spec } from "@json-render/react";
import { expect, it, mock } from "bun:test";
import { BLOCK_ERROR_KIND, markBrokenBlocks, type RenderSpec } from "./blockErrors";
import { catalog } from "./catalog";
import { CmsRenderer } from "./CmsRenderer";
import { registry } from "./registry";

const hero = { title: "Welcome", subtitle: null, image: null, cta: null };

function spec(elements: Record<string, { type: string; props: unknown }>, errors?: Record<string, string>): RenderSpec {
    return {
        root: "$root",
        elements: { ...elements, $root: { type: "Fragment", props: {}, children: Object.keys(elements) } },
        ...(errors ? { errors } : {}),
    } as RenderSpec;
}

function renderSpec(s: RenderSpec, showErrors: boolean, reg: Record<string, unknown> = registry) {
    return render(
        <JSONUIProvider registry={registry}>
            <CmsRenderer spec={s} catalog={catalog} registry={reg} showErrors={showErrors} />
        </JSONUIProvider>,
    );
}

it("replaces a block the server failed to resolve with the error element and leaves the rest untouched", () => {
    const out = markBrokenBlocks(
        spec({ a: { type: "Hero", props: hero }, b: { type: "Hero", props: {} } }, { b: "Block [Hero] failed to resolve." }),
        catalog,
        registry,
    );

    expect(out.elements.a).toEqual({ type: "Hero", props: hero });
    expect(out.elements.b).toEqual({ type: BLOCK_ERROR_KIND, props: { kind: "Hero", message: "Block [Hero] failed to resolve." } });
    expect(out.elements.$root.children).toEqual(["a", "b"]);
});

it("names a kind with no registered component", () => {
    const out = markBrokenBlocks(spec({ a: { type: "Ghost", props: {} } }), catalog, registry);

    expect(out.elements.a.props).toEqual({ kind: "Ghost", message: "Block [Ghost] has no component in the registry." });
});

it("names the props that fail the catalog schema", () => {
    const out = markBrokenBlocks(spec({ a: { type: "Hero", props: { title: 42 } } }), catalog, registry);

    expect(out.elements.a.type).toBe(BLOCK_ERROR_KIND);
    expect((out.elements.a.props as { message: string }).message).toStartWith("Block [Hero] props do not match its schema — title:");
});

it("returns the same spec object when every block is healthy", () => {
    const s = spec({ a: { type: "Hero", props: hero } });

    expect(markBrokenBlocks(s, catalog, registry)).toBe(s as Spec);
});

it("draws the placeholder with showErrors and nothing without, while healthy siblings render either way", () => {
    const s = spec({ a: { type: "Hero", props: hero }, b: { type: "Ghost", props: {} } });

    const shown = renderSpec(s, true);
    expect(shown.getByText("Welcome")).toBeDefined();
    expect(shown.getByTestId("cms-block-error").textContent).toBe("Block [Ghost] has no component in the registry.");
    shown.unmount();

    const hidden = renderSpec(s, false);
    expect(hidden.getByText("Welcome")).toBeDefined();
    expect(hidden.queryByTestId("cms-block-error")).toBeNull();
});

it("catches a component that throws while rendering and shows the placeholder in its place", () => {
    const original = console.error;
    console.error = mock(() => undefined);
    try {
        const throwing = {
            ...registry,
            Hero: () => {
                throw new Error("boom");
            },
        };
        const { getByTestId } = renderSpec(spec({ a: { type: "Hero", props: hero } }), true, throwing);

        expect(getByTestId("cms-block-error").textContent).toBe("Block [Hero] threw while rendering: boom");
    } finally {
        console.error = original;
    }
});
