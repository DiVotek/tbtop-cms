import { render } from "@testing-library/react";
import { renderToString } from "react-dom/server";
import { JSONUIProvider } from "@json-render/react";
import { expect, it, mock } from "bun:test";
import { dropBrokenBlocks, type RenderSpec } from "./blockErrors";
import { catalog } from "./catalog";
import { CmsRenderer } from "./CmsRenderer";
import { registry } from "./registry";

const hero = { title: "Welcome", subtitle: null, image: null, cta: null };

function spec(elements: Record<string, { type: string; props: unknown }>): RenderSpec {
    return {
        root: "$root",
        elements: { ...elements, $root: { type: "Fragment", props: {}, children: Object.keys(elements) } },
    } as RenderSpec;
}

function renderSpec(s: RenderSpec, showErrors: boolean, reg: Record<string, unknown> = registry) {
    return render(
        <JSONUIProvider registry={registry}>
            <CmsRenderer spec={s} catalog={catalog} registry={reg} showErrors={showErrors} />
        </JSONUIProvider>,
    );
}

/** Runs `fn` with console.error captured; returns the first argument of every call. */
function consoleLines(fn: () => void): string[] {
    const original = console.error;
    const logged = mock((..._args: unknown[]) => undefined);
    console.error = logged;
    try {
        fn();
    } finally {
        console.error = original;
    }

    return logged.mock.calls.map((call) => String(call[0]));
}

function throwingRegistry(message: string) {
    // A fresh registry object each time: the guarded registry is memoised per registry.
    return {
        ...registry,
        Hero: () => {
            throw new Error(message);
        },
    };
}

it("drops a kind with no registered component and its place in children", () => {
    const { spec: out, dropped } = dropBrokenBlocks(spec({ a: { type: "Hero", props: hero }, b: { type: "Ghost", props: {} } }), catalog, registry);

    expect(Object.keys(out.elements).sort()).toEqual(["$root", "a"]);
    expect(out.elements.$root.children).toEqual(["a"]);
    expect(dropped).toEqual([{ id: "b", kind: "Ghost", message: "Block [Ghost] has no component in the registry." }]);
});

it("treats a prototype property name stored as a kind as unregistered", () => {
    const { dropped } = dropBrokenBlocks(spec({ a: { type: "constructor", props: {} } }), catalog, registry);

    expect(dropped.map((block) => block.kind)).toEqual(["constructor"]);
});

it("returns the same spec object when every block is healthy", () => {
    const s = spec({ a: { type: "Hero", props: hero } });

    expect(dropBrokenBlocks(s, catalog, registry)).toEqual({ spec: s, dropped: [] });
});

it("renders nothing for props that fail the schema, and says so on the console only with showErrors", () => {
    const s = spec({ a: { type: "Hero", props: hero }, b: { type: "Hero", props: { title: 42 } } });

    const silent = consoleLines(() => {
        const view = renderSpec(s, false);
        expect(view.getAllByTestId("hero")).toHaveLength(1);
        expect(view.getByText("Welcome")).toBeDefined();
        view.unmount();
    });
    expect(silent).toEqual([]);

    const loud = consoleLines(() => {
        const view = renderSpec(s, true);
        expect(view.getAllByTestId("hero")).toHaveLength(1);
        view.unmount();
    });
    expect(loud).toHaveLength(1);
    expect(loud[0]).toStartWith("[tbtop-cms] Block [Hero] props do not match its schema — title:");
});

it("renders nothing for a component that throws, and says so on the console only with showErrors", () => {
    const silent = consoleLines(() => {
        const view = renderSpec(spec({ a: { type: "Hero", props: hero } }), false, throwingRegistry("quiet boom"));
        expect(view.container.textContent).toBe("");
        view.unmount();
    });
    expect(silent.filter((line) => line.startsWith("[tbtop-cms]"))).toEqual([]);

    const loud = consoleLines(() => {
        const view = renderSpec(spec({ a: { type: "Hero", props: hero } }), true, throwingRegistry("loud boom"));
        expect(view.container.textContent).toBe("");
        view.unmount();
    });
    expect(loud.filter((line) => line.startsWith("[tbtop-cms]"))).toEqual(["[tbtop-cms] Block [Hero] threw while rendering."]);
});

it("renders nothing in place of a throwing component on the server, where no boundary runs", () => {
    const win = globalThis.window;
    let html = "";
    const lines = consoleLines(() => {
        try {
            // happy-dom defines window for every test; the server pass is the one without it.
            Object.defineProperty(globalThis, "window", { value: undefined, configurable: true, writable: true });
            html = renderToString(
                <JSONUIProvider registry={registry}>
                    <CmsRenderer spec={spec({ a: { type: "Hero", props: hero } })} catalog={catalog} registry={throwingRegistry("server boom")} showErrors={false} />
                </JSONUIProvider>,
            );
        } finally {
            Object.defineProperty(globalThis, "window", { value: win, configurable: true, writable: true });
        }
    });

    expect(html).not.toContain("server boom");
    expect(html).not.toContain("Welcome");
    expect(lines).toEqual([]);
});

it("renders nothing, without throwing, for a spec with no elements (an older server's error-page fallback sends [])", () => {
    const empty = [] as unknown as RenderSpec;

    expect(dropBrokenBlocks(empty, catalog, registry).dropped).toEqual([]);
    expect(renderSpec(empty, true).container.innerHTML).toBe("");
});
