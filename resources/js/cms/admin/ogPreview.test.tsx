import { fireEvent, render } from "@testing-library/react";
import { beforeEach, describe, expect, it } from "bun:test";
import type { RenderProps } from "@tbtop/inertia-admin";
import { OgPreviewBlock } from "./ogPreview";

interface LocaleCard {
	title: string;
	description: string;
}

interface OgImage {
	url: string;
	alt: string | null;
}

interface OgPreviewOptions {
	byLocale: Record<string, LocaleCard>;
	image: OgImage | null;
	host: string;
	siteName: string;
	defaultLocale: string;
	emptyTitle: string;
	emptyDescription: string;
	labels: {
		sectionTitle: string;
		noImageHint: string;
	};
}

// The block remembers its open state in localStorage; a toggle left behind by
// one test would flip the next test's click from "expand" to "collapse".
beforeEach(() => localStorage.clear());

function baseOptions(overrides: Partial<OgPreviewOptions> = {}): OgPreviewOptions {
	return {
		byLocale: { en: { title: "A great page", description: "A great description." } },
		image: { url: "https://example.test/og.jpg", alt: "An image" },
		host: "example.test",
		siteName: "Acme",
		defaultLocale: "en",
		emptyTitle: "No title yet",
		emptyDescription: "No description yet.",
		labels: {
			sectionTitle: "Social preview",
			noImageHint: "No image resolved.",
		},
		...overrides,
	};
}

function renderBlock(options: OgPreviewOptions) {
	// Only `options` is read by the component; the rest of RenderProps is
	// materialize/render-tree plumbing this leaf block never touches.
	return render(<OgPreviewBlock options={options} {...({} as Omit<RenderProps<OgPreviewOptions>, "options">)} />);
}

describe("OgPreviewBlock", () => {
	it("expands to show the default locale's title, description and image", () => {
		const { getByText, container } = renderBlock(baseOptions());

		fireEvent.click(getByText("Social preview"));

		expect(getByText("A great page")).toBeDefined();
		expect(getByText("A great description.")).toBeDefined();
		expect(container.querySelector("img")?.getAttribute("src")).toBe("https://example.test/og.jpg");
	});

	it("falls back to the empty-state copy when the active locale's card has no title or description", () => {
		const { getByText } = renderBlock(baseOptions({ byLocale: { en: { title: "", description: "" } } }));

		fireEvent.click(getByText("Social preview"));

		expect(getByText("No title yet")).toBeDefined();
		expect(getByText("No description yet.")).toBeDefined();
	});

	it("shows the no-image hint instead of an <img> when image is null", () => {
		const { getByText, container } = renderBlock(baseOptions({ image: null }));

		fireEvent.click(getByText("Social preview"));

		expect(getByText("No image resolved.")).toBeDefined();
		expect(container.querySelector("img")).toBeNull();
	});

	it("falls back to the default locale's card when byLocale has no entry for it", () => {
		// useContentLocale() returns null with no locale tab bar in the DOM
		// (single-locale forms render none), so the block must resolve via
		// defaultLocale rather than leaving the card blank.
		const { getByText } = renderBlock(
			baseOptions({ defaultLocale: "en", byLocale: { en: { title: "Fallback title", description: "Fallback description." } } }),
		);

		fireEvent.click(getByText("Social preview"));

		expect(getByText("Fallback title")).toBeDefined();
	});
});
