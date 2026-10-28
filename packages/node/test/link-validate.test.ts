import { describe, expect, test } from "bun:test";
import { linkError } from "../src/admin-client/link/validate";

const link = (label: string) => ({ type: "external" as const, page: null, value: "https://example.com", anchor: null, label: { en: label } });

describe("the link field's label check", () => {
	test("counts characters like the server (mb_strlen), so 255 emoji pass and 256 do not", () => {
		expect(linkError(link("😀".repeat(255)))).toBeNull();
		expect(linkError(link("😀".repeat(256)))).toBe("label");
	});
});
