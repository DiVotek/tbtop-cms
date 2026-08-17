import { render } from "@testing-library/react";
import { describe, expect, it } from "bun:test";
import type { RenderProps } from "@tbtop/inertia-admin";
import { SeoChecklistBlock } from "./seoChecklist";

type Status = "pass" | "warn" | "fail";

interface Check {
	key: string;
	status: Status;
	label: string;
	detail: string;
	required: boolean;
	statusWord: string;
}

interface SeoChecklistOptions {
	byLocale: Record<string, Check[]>;
	defaultLocale: string;
	labels: {
		required: string;
		optional: string;
	};
}

function check(overrides: Partial<Check>): Check {
	return {
		key: "title",
		status: "pass",
		label: "Meta title",
		detail: "Set.",
		required: true,
		statusWord: "OK",
		...overrides,
	};
}

function renderBlock(options: SeoChecklistOptions) {
	return render(
		<SeoChecklistBlock options={options} {...({} as Omit<RenderProps<SeoChecklistOptions>, "options">)} />,
	);
}

describe("SeoChecklistBlock", () => {
	it("renders the default locale's checks when byLocale has no active-tab entry", () => {
		const { getByText } = renderBlock({
			byLocale: {
				en: [check({ key: "title", label: "Meta title" })],
			},
			defaultLocale: "en",
			labels: { required: "Required", optional: "Worth doing" },
		});

		expect(getByText("Meta title")).toBeDefined();
	});

	it("splits required and optional checks into their own groups", () => {
		const { getByText } = renderBlock({
			byLocale: {
				en: [
					check({ key: "title", label: "Meta title", required: true }),
					check({ key: "og_image", label: "OG image", required: false, status: "warn", statusWord: "Check" }),
				],
			},
			defaultLocale: "en",
			labels: { required: "Required", optional: "Worth doing" },
		});

		expect(getByText("Required")).toBeDefined();
		expect(getByText("Worth doing")).toBeDefined();
		expect(getByText("Meta title")).toBeDefined();
		expect(getByText("OG image")).toBeDefined();
	});

	it("renders nothing for a locale with an empty check list rather than crashing", () => {
		const { queryByText } = renderBlock({
			byLocale: { en: [] },
			defaultLocale: "en",
			labels: { required: "Required", optional: "Worth doing" },
		});

		expect(queryByText("Required")).toBeNull();
		expect(queryByText("Worth doing")).toBeNull();
	});
});
