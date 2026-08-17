import { cleanup, render, screen } from "@testing-library/react";
import { afterEach, describe, expect, it } from "bun:test";
import type { RenderProps } from "@tbtop/inertia-admin";
import { OgPreviewBlock } from "./ogPreview";

afterEach(() => cleanup());

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
	it("expands to show the default locale's title, description and image", async () => {
		renderBlock(baseOptions());

		await screen.findByText("Social preview").then((button) => button.click());

		expect(screen.getByText("A great page")).toBeDefined();
		expect(screen.getByText("A great description.")).toBeDefined();
		expect(screen.getByRole("img").getAttribute("src")).toBe("https://example.test/og.jpg");
	});

	it("falls back to the empty-state copy when the active locale's card has no title or description", async () => {
		renderBlock(baseOptions({ byLocale: { en: { title: "", description: "" } } }));

		await screen.findByText("Social preview").then((button) => button.click());

		expect(screen.getByText("No title yet")).toBeDefined();
		expect(screen.getByText("No description yet.")).toBeDefined();
	});

	it("shows the no-image hint instead of an <img> when image is null", async () => {
		renderBlock(baseOptions({ image: null }));

		await screen.findByText("Social preview").then((button) => button.click());

		expect(screen.getByText("No image resolved.")).toBeDefined();
		expect(screen.queryByRole("img")).toBeNull();
	});

	it("falls back to the default locale's card when byLocale has no entry for it", async () => {
		// useContentLocale() returns null with no locale tab bar in the DOM
		// (single-locale forms render none), so the block must resolve via
		// defaultLocale rather than leaving the card blank.
		renderBlock(baseOptions({ defaultLocale: "en", byLocale: { en: { title: "Fallback title", description: "Fallback description." } } }));

		await screen.findByText("Social preview").then((button) => button.click());

		expect(screen.getByText("Fallback title")).toBeDefined();
	});
});
