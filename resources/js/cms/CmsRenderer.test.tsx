import { render } from "@testing-library/react";
import { renderToString } from "react-dom/server";
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

it("treats a prototype property name stored as a kind as unregistered", () => {
    const out = markBrokenBlocks(spec({ a: { type: "constructor", props: {} } }), catalog, registry);

    expect(out.elements.a.props).toEqual({ kind: "constructor", message: "Block [constructor] has no component in the registry." });
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

    const original = console.error;
    const logged = mock((..._args: unknown[]) => undefined);
    console.error = logged;
    try {
        const hidden = renderSpec(s, false);
        expect(hidden.getByText("Welcome")).toBeDefined();
        expect(hidden.queryByTestId("cms-block-error")).toBeNull();
        // Hidden from the visitor, not from whoever reads the log.
        expect(logged.mock.calls.map((call) => String(call[0]))).toEqual([
            "[tbtop-cms] Block [Ghost] is hidden from the page: Block [Ghost] has no component in the registry.",
        ]);
    } finally {
        console.error = original;
    }
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

it("renders the placeholder in place of a throwing component on the server, where no boundary runs", () => {
    const originalError = console.error;
    console.error = mock(() => undefined);
    const win = globalThis.window;
    // A fresh registry object: the guarded registry is memoised per registry, and the client/server choice is made there.
    const throwing = {
        ...registry,
        Hero: () => {
            throw new Error("boom");
        },
    };
    try {
        // happy-dom defines window for every test; the server pass is the one without it.
        Object.defineProperty(globalThis, "window", { value: undefined, configurable: true, writable: true });
        const html = renderToString(
            <JSONUIProvider registry={registry}>
                <CmsRenderer spec={spec({ a: { type: "Hero", props: hero } })} catalog={catalog} registry={throwing} showErrors={true} />
            </JSONUIProvider>,
        );

        expect(html).toContain("Block [Hero] threw while rendering: boom");
    } finally {
        Object.defineProperty(globalThis, "window", { value: win, configurable: true, writable: true });
        console.error = originalError;
    }
});
