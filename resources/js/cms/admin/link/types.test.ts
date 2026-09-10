import { describe, expect, it } from "bun:test";
import { emptyLink, parseLinkValue, withLabel } from "./types";

describe("withLabel", () => {
	const base = emptyLink("email");

	it("writes the text under the locale it was given", () => {
		expect(withLabel(base, "uk", "Напишіть").label).toEqual({ uk: "Напишіть" });
	});

	it("leaves the other locales alone", () => {
		const one = withLabel(base, "en", "Mail us");

		expect(withLabel(one, "uk", "Напишіть").label).toEqual({ en: "Mail us", uk: "Напишіть" });
	});

	it("drops just the cleared locale when others remain", () => {
		const two = withLabel(withLabel(base, "en", "Mail us"), "uk", "Напишіть");

		expect(withLabel(two, "en", "").label).toEqual({ uk: "Напишіть" });
	});

	it("collapses the map to null once the last entry is cleared", () => {
		const one = withLabel(base, "en", "Mail us");

		expect(withLabel(one, "en", "").label).toBeNull();
	});

	it("treats whitespace as empty so a spaces-only label stores nothing", () => {
		expect(withLabel(base, "en", "   ").label).toBeNull();
	});
});

describe("parseLinkValue label", () => {
	it("keeps a per-locale map as authored", () => {
		const link = parseLinkValue({ type: "email", value: "a@b.co", label: { en: "Mail us" } });

		expect(link?.label).toEqual({ en: "Mail us" });
	});

	it("accepts a label already collapsed to a string by the flattener", () => {
		const link = parseLinkValue({ type: "email", value: "a@b.co", label: "Mail us" });

		expect(link?.label).toEqual({ "*": "Mail us" });
	});

	it("reads a null label as no label", () => {
		expect(parseLinkValue({ type: "email", value: "a@b.co", label: null })?.label).toBeNull();
	});

	it("reads an empty map as no label", () => {
		expect(parseLinkValue({ type: "email", value: "a@b.co", label: {} })?.label).toBeNull();
	});

	it("drops blank entries rather than storing them", () => {
		const link = parseLinkValue({ type: "email", value: "a@b.co", label: { en: "", uk: "Напишіть" } });

		expect(link?.label).toEqual({ uk: "Напишіть" });
	});
});
