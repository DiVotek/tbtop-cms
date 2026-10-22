import { describe, expect, test } from "bun:test";
import { defineRegistry, JSONUIProvider } from "@json-render/react";
import type { ReactNode } from "react";
import { renderToString } from "react-dom/server";
import { cmsBlocks } from "../src";
import { CmsHead, CmsRenderer, cmsCatalog, cmsComponents, seoHead } from "../src/react";
import { makeCms } from "./support";

const catalog = cmsCatalog(cmsBlocks);
const { registry } = defineRegistry(catalog, { components: cmsComponents });

/** Hosts wrap every spec of a page (header, body, footer) in one provider, as the PHP CmsPage does. */
const page = (children: ReactNode) => renderToString(<JSONUIProvider registry={registry}>{children}</JSONUIProvider>);

async function home(setup?: (cms: Awaited<ReturnType<typeof makeCms>>["cms"]) => Promise<void>) {
	const { cms } = await makeCms();
	await setup?.(cms);
	const result = await cms.loadPage(new Request("https://site.test/"));
	if (result.type !== "page") {
		throw new Error(result.type);
	}
	return result.props;
}

describe("@tbtop/cms/react", () => {
	test("seoHead maps the seo prop onto TanStack's head() and escapes JSON-LD", async () => {
		const props = await home((cms) => cms.site().settings.set("site.name", "Acme</script><script>alert(1)</script>"));
		const head = seoHead(props.seo);
		expect(head.meta[0]).toEqual({ title: props.seo.title });
		expect(head.meta).toContainEqual({ property: "og:type", content: "website" });
		expect(head.links).toContainEqual({ rel: "canonical", href: "https://site.test" });
		expect(head.links).toContainEqual({ rel: "alternate", hrefLang: "uk", href: "https://site.test/uk" });
		const json = head.scripts.map((script) => script.children).join("");
		expect(json).not.toContain("</script>");
		expect(json).toContain("Acme\\u003c/script\\u003e");
		expect(JSON.parse(head.scripts[0]?.children ?? "{}")["@context"]).toBe("https://schema.org");
	});

	test("CmsHead renders the same tags as React elements", async () => {
		const props = await home();
		const html = renderToString(<CmsHead seo={props.seo} />);
		expect(html).toContain(`<title>${props.seo.title}</title>`);
		expect(html).toContain('hrefLang="uk"');
		expect(html).toContain('<script type="application/ld+json">');
	});

	test("CmsRenderer draws the page blocks with the built-in components on the server", async () => {
		const props = await home();
		const html = page(<CmsRenderer spec={props.render} catalog={catalog} registry={registry} showErrors={false} />);
		expect(html).toContain('data-testid="hero"');
		expect(html).toContain('data-testid="contact-form"');
		expect(html).toMatch(/action="https:\/\/site\.test\/_cms\/forms\/en\/\d+\/[^"]+"/);
		expect(html).toContain('name="website"');
	});

	test("a block kind without a component renders nothing instead of breaking the page", async () => {
		const props = await home();
		const { Hero: _, ...withoutHero } = registry as Record<string, unknown>;
		const html = page(<CmsRenderer spec={props.render} catalog={catalog} registry={withoutHero} showErrors={false} />);
		expect(html).not.toContain('data-testid="hero"');
		expect(html).toContain('data-testid="contact-form"');
	});
});
