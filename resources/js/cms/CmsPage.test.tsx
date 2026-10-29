import { render } from "@testing-library/react";
import { expect, it, mock } from "bun:test";

const props: Record<string, unknown> = {
    render: { root: "$root", elements: { $root: { type: "Fragment", props: {}, children: [] } } },
    seo: { title: "", description: "", alternates: [], ogLocaleAlternates: [], jsonLd: [] },
    isPreview: true,
    cms: { debug: false, strings: { previewBanner: "Чернетка — сторінку не опубліковано." } },
};

const inertia = await import("@inertiajs/react");
mock.module("@inertiajs/react", () => ({
    ...inertia,
    usePage: () => ({ props }),
    Head: () => null,
}));

const { default: CmsPage } = await import("./CmsPage");

it("shows the draft-preview banner in the text the server translated", () => {
    const { container } = render(<CmsPage />);

    expect(container.textContent).toContain("Чернетка — сторінку не опубліковано.");
    expect(container.textContent).not.toContain("Draft preview");
});

it("shows the error heading when there is no error page to render", () => {
    props.isPreview = false;
    props.page = { id: null, heading: "Page not found" };
    const { container } = render(<CmsPage />);

    expect(container.querySelector("h1")?.textContent).toBe("Page not found");
});

it("leaves the heading of a real page to its blocks", () => {
    props.isPreview = false;
    props.page = { id: 5, heading: "About us" };
    const { container } = render(<CmsPage />);

    expect(container.querySelector("h1")).toBeNull();
});
