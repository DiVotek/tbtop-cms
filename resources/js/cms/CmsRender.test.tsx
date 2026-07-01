import { render } from "@testing-library/react";
import { JSONUIProvider, Renderer, type Spec } from "@json-render/react";
import { expect, it } from "bun:test";
import { registry } from "./registry";

function renderSpec(spec: Spec) {
    return render(
        <JSONUIProvider registry={registry}>
            <Renderer spec={spec} registry={registry} />
        </JSONUIProvider>,
    );
}

it("renders a hero block from a json-render spec", () => {
    const { getByTestId, getByText } = renderSpec({
        root: "$root",
        elements: {
            "hero-1": {
                type: "Hero",
                props: {
                    title: "Welcome",
                    subtitle: "A subtitle",
                    image: null,
                    cta: { label: "Go", href: "https://example.test" },
                },
            },
            $root: { type: "Fragment", props: {}, children: ["hero-1"] },
        },
    });

    expect(getByTestId("hero")).toBeDefined();
    expect(getByText("Welcome")).toBeDefined();
    expect(getByText("A subtitle")).toBeDefined();
    expect(getByText("Go").getAttribute("href")).toBe("https://example.test");
});

it("drops an unknown block type without crashing", () => {
    const { queryByTestId } = renderSpec({
        root: "$root",
        elements: {
            "x-1": { type: "Nope", props: {} },
            $root: { type: "Fragment", props: {}, children: ["x-1"] },
        },
    });

    expect(queryByTestId("hero")).toBeNull();
});
