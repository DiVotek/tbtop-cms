import { describe, expect, test } from "bun:test";
import { RedirectError } from "../src";
import { get, makeCms } from "./support";

describe("redirects.allowedExternalHosts (q11)", () => {
	test("an external target on a listed host is stored and followed, matched case-insensitively", async () => {
		const { cms } = await makeCms(undefined, { redirects: { allowedExternalHosts: ["Partner.Example"] } });
		await cms.site().redirects.create({ fromPath: "promo", toPath: "https://partner.EXAMPLE/deal" });
		const result = await cms.loadPage(get("/promo"));
		expect(result).toMatchObject({ type: "redirect", status: 301, location: "https://partner.EXAMPLE/deal" });
	});

	test("other hosts and non-http schemes stay rejected", async () => {
		const { cms } = await makeCms(undefined, { redirects: { allowedExternalHosts: ["partner.example"] } });
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "https://evil.example/x" })).rejects.toThrow(
			new RedirectError("External redirect host is not allowlisted."),
		);
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "//partner.example/x" })).rejects.toThrow(
			new RedirectError("External redirect target must be a valid http(s) URL with a host."),
		);
	});

	test("without the key every external target is rejected, as before", async () => {
		const { cms } = await makeCms();
		await expect(cms.site().redirects.create({ fromPath: "promo", toPath: "https://partner.example/x" })).rejects.toThrow(
			new RedirectError("External redirect host is not allowlisted."),
		);
	});
});
