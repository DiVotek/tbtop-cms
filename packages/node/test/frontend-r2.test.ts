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

	test("a non-ASCII path is percent-encoded and lands on the same page (R2-frontend-2)", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/en/%D0%BF%D1%80%D0%BE"));
		expect(result).toEqual({ type: "redirect", status: 301, location: "/%D0%BF%D1%80%D0%BE" });
		expect(followed(result).pathname).toBe("/%D0%BF%D1%80%D0%BE");
	});
});

describe("stored redirects", () => {
	test("a non-ASCII target is percent-encoded, existing escapes kept (R2-frontend-2)", async () => {
		const { cms } = await makeCms();
		await cms.site().redirects.create({ fromPath: "old", toPath: "новини" });
		await cms.site().redirects.create({ fromPath: "kept", toPath: "caf%C3%A9 x" });
		expect(followed(await cms.loadPage(get("/old"))).pathname).toBe("/%D0%BD%D0%BE%D0%B2%D0%B8%D0%BD%D0%B8");
		expect(followed(await cms.loadPage(get("/kept"))).pathname).toBe("/caf%C3%A9%20x");
	});

	test("an external non-ASCII target becomes a valid header to the same host (R2-frontend-2)", async () => {
		const { cms } = await makeCms();
		await cms.site().redirects.create({ fromPath: "out", toPath: "https://bücher.example/ä?q=ü" });
		await cms.site().redirects.create({ fromPath: "plain", toPath: "https://example.com" });
		const target = followed(await cms.loadPage(get("/out")));
		expect(target.href).toBe("https://xn--bcher-kva.example/%C3%A4?q=%C3%BC");
		expect(await cms.loadPage(get("/plain"))).toEqual({ type: "redirect", status: 301, location: "https://example.com" });
	});

	test("an internal target with a control character cannot become protocol-relative (R2-frontend-1)", async () => {
		const { cms } = await makeCms();
		await cms.site().redirects.create({ fromPath: "tab", toPath: "\t//evil.com" });
		expect(followed(await cms.loadPage(get("/tab"))).origin).toBe("https://site.test");
	});
});

describe("routing", () => {
	test("empty path segments are kept, so the slug lookup misses as in PHP (R2-frontend-6)", async () => {
		const { cms } = await makeCms();
		expect((await cms.loadPage(get("/blog/first-post"))).type).toBe("page");
		expect((await cms.loadPage(get("/blog//first-post"))).type).toBe("notFound");
		expect((await cms.loadPage(get("/blog///first-post"))).type).toBe("notFound");
		expect((await cms.loadPage(get("/blog/first-post/"))).type).toBe("page");
	});
});
