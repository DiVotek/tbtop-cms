import { fireEvent, render } from "@testing-library/react";
import { afterAll, beforeAll, beforeEach, describe, expect, it } from "bun:test";
import type { RenderProps } from "@tbtop/inertia-admin";
import { SerpPreviewBlock } from "./serpPreview";

type Options = RenderProps<Parameters<typeof SerpPreviewBlock>[0]["options"]>["options"];

// The test DOM has no canvas. A fixed 10px per character stands in for the
// font, so the expected widths can be read off the strings.
const originalGetContext = HTMLCanvasElement.prototype.getContext;

beforeAll(() => {
	HTMLCanvasElement.prototype.getContext = (() => ({
		font: "",
		measureText: (text: string) => ({ width: text.length * 10 }),
	})) as unknown as typeof HTMLCanvasElement.prototype.getContext;
});

// The open state is remembered in localStorage; start every test collapsed.
beforeEach(() => localStorage.clear());

afterAll(() => {
	HTMLCanvasElement.prototype.getContext = originalGetContext;
});

function options(title: string): Options {
	return {
		byLocale: { en: { url: "example.test", title, description: "" } },
		defaultLocale: "en",
		emptyTitle: "No title yet",
		emptyDescription: "No description yet.",
		labels: {
			desktop: "Desktop",
			mobile: "Mobile",
			titleWidth: "Title :width/:limit px",
			descriptionLength: "Description :length/:limit",
			truncated: "Truncated",
			sectionTitle: "Search preview",
			summary: "",
			expand: "Expand",
			collapse: "Collapse",
		},
	};
}

describe("SerpPreviewBlock title meter", () => {
	it("counts a wrapped title as full lines plus the width of its last line", () => {
		// Mobile column is 460px: a 450px first line, then a 100px last line.
		const title = `${"a".repeat(45)} ${"b".repeat(10)}`;
		const { getByText } = render(
			<SerpPreviewBlock options={options(title)} {...({} as Omit<RenderProps<Options>, "options">)} />,
		);

		fireEvent.click(getByText("Search preview"));
		fireEvent.click(getByText("Mobile"));

		expect(getByText("Title 560/920 px")).toBeDefined();
	});
});
