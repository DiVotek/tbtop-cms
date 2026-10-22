import { describe, expect, test } from "bun:test";
import type { LoadResult } from "../src";
import { get, makeCms } from "./support";

/** The Location a host sends must be a valid header and stay on the site origin unless the editor chose another. */
function followed(result: LoadResult): URL {
	if (result.type !== "redirect") {
		throw new Error(`expected a redirect, got ${result.type}`);
	}
	const response = new Response(null, { status: result.status, headers: { Location: result.location } });
	return new URL(response.headers.get("Location") ?? "", "https://site.test/");
}

describe("default-locale prefix redirect", () => {
	test("an encoded slash or backslash cannot send the visitor to another host (R2-frontend-1)", async () => {
		const { cms } = await makeCms();
		for (const path of ["/en/%2F%2Fevil.com", "/en/%2Fevil.com", "/en/%5Cevil.com", "/en//%2Fevil.com", "/en/%09/evil.com"]) {
			const target = followed(await cms.loadPage(get(path)));
			expect(target.origin).toBe("https://site.test");
		}
	});

	test("decoded slashes split into segments and empty ones drop, as PHP's decoded path does (R2-frontend-1)", async () => {
		const { cms } = await makeCms();
		expect(await cms.loadPage(get("/en/%2F%2Fevil.com"))).toEqual({ type: "redirect", status: 301, location: "/evil.com" });
		expect(await cms.loadPage(get("/en//blog//first-post?x=1"))).toEqual({ type: "redirect", status: 301, location: "/blog/first-post?x=1" });
	});
});
