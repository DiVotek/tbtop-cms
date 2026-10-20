import { describe, expect, it } from "bun:test";
import { linkError } from "./validate";
import type { LinkType, LinkValue } from "./types";

function link(type: LinkType, value: string | null, anchor: string | null = null): LinkValue {
	return type === "page"
		? { type, page: value, value: null, anchor, label: null }
		: { type, page: null, value, anchor: null, label: null };
}

describe("external", () => {
	it("accepts http and https urls", () => {
		expect(linkError(link("external", "https://example.com/a?b=c"))).toBeNull();
		expect(linkError(link("external", "http://example.com"))).toBeNull();
	});

	it("accepts every allowlisted messenger scheme", () => {
		for (const url of [
			"viber://chat?number=%2B380441234567",
			"tg://resolve?domain=someone",
			"whatsapp://send?phone=380441234567",
			"skype:someone?chat",
			"sms:+380441234567",
		]) {
			expect(linkError(link("external", url))).toBeNull();
		}
	});

	it("accepts an allowlisted scheme in any case", () => {
		expect(linkError(link("external", "VIBER://chat?number=1"))).toBeNull();
	});

	it("rejects a dangerous scheme", () => {
		for (const url of [
			"javascript:alert(1)",
			"JAVASCRIPT:alert(1)",
			"data:text/html,x",
			"file:///etc/passwd",
			"vbscript:msgbox(1)",
		]) {
			expect(linkError(link("external", url))).toBe("external");
		}
	});

	it("rejects a scheme with no target", () => {
		expect(linkError(link("external", "viber:"))).toBe("external");
	});

	it("rejects a bare string", () => {
		expect(linkError(link("external", "example.com"))).toBe("external");
	});
});

describe("email", () => {
	it("accepts a well-formed address", () => {
		expect(linkError(link("email", "hi@example.com"))).toBeNull();
	});

	it("rejects an address with no domain dot", () => {
		expect(linkError(link("email", "hi@example"))).toBe("email");
	});
});

describe("phone", () => {
	it("accepts a formatted number", () => {
		expect(linkError(link("phone", "+380 (44) 123-45-67"))).toBeNull();
	});

	it("rejects fewer than six digits", () => {
		expect(linkError(link("phone", "+38 04"))).toBe("phone");
	});

	it("rejects letters", () => {
		expect(linkError(link("phone", "+380 44 CALL"))).toBe("phone");
	});
});

describe("page", () => {
	it("accepts an id with a plain anchor", () => {
		expect(linkError(link("page", "7", "how-it-works"))).toBeNull();
	});

	it("stays quiet for an unpicked page, leaving `required` to the server", () => {
		expect(linkError(link("page", null))).toBeNull();
	});

	it("still flags a bad anchor before a page is picked", () => {
		expect(linkError(link("page", null, "#how"))).toBe("anchor");
	});

	it("reports an anchor carrying a hash", () => {
		expect(linkError(link("page", "7", "#how"))).toBe("anchor");
	});

	it("trims the anchor before checking it, as the server does", () => {
		expect(linkError(link("page", "7", " how "))).toBeNull();
		expect(linkError(link("page", "7", "   "))).toBeNull();
	});
});

it("stays quiet on an untouched value", () => {
	expect(linkError(link("email", null))).toBeNull();
	expect(linkError(link("external", ""))).toBeNull();
});

describe("label length", () => {
	const withLabels = (label: Record<string, string | null>): LinkValue => ({
		type: "email",
		page: null,
		value: "hi@example.com",
		anchor: null,
		label,
	});

	it("accepts a label of exactly 255 characters", () => {
		expect(linkError(withLabels({ en: "a".repeat(255) }))).toBeNull();
	});

	it("flags a label past 255 characters", () => {
		expect(linkError(withLabels({ en: "a".repeat(256) }))).toBe("label");
	});

	it("flags an over-long label in any locale, not just the first", () => {
		expect(linkError(withLabels({ en: "Mail us", uk: "a".repeat(300) }))).toBe("label");
	});

	it("ignores a null label entry", () => {
		expect(linkError(withLabels({ en: null }))).toBeNull();
	});
});
