import { describe, expect, test } from "bun:test";
import { makeCms, get } from "./support";

describe("loadPage", () => {
	test("the home page: props, render spec, shared cms props", async () => {
		const { cms, ids } = await makeCms();
		const result = await cms.loadPage(get("/"));
		if (result.type !== "page") {
			throw new Error(`expected a page, got ${result.type}`);
		}
		const { props } = result;
		expect(props.page).toMatchObject({ id: ids.home, type: "page", status: "published", name: "Home", title: "Welcome" });
		expect(props.path).toBe("");
		expect(props.isPreview).toBe(false);
		expect(props.breadcrumbs).toEqual([]);
		const root = props.render.elements.$root;
		expect(root?.type).toBe("Fragment");
		expect(root?.children?.length).toBe(2);
		const [hero, contact] = (root?.children ?? []).map((id) => props.render.elements[id]);
		expect(hero).toEqual({
			type: "Hero",
			props: {
				title: "Welcome to tbtop CMS",
				subtitle: "This hero block is rendered through json-render.",
				image: null,
				cta: { label: "Read the blog", href: "/blog" },
			},
		});
		// The shared block's data, with the form action bound to this page.
		expect(contact?.type).toBe("ContactForm");
		expect(contact?.props).toMatchObject({ heading: "Write to us", submitLabel: "Send" });
		expect(String(contact?.props.action)).toMatch(new RegExp(`^/_cms/forms/en/${ids.home}/fx\\d+$`));
		expect(props.cms).toMatchObject({
			locale: "en",
			defaultLocale: "en",
			languages: [
				{ locale: "en", name: "English", is_default: true },
				{ locale: "uk", name: "Українська", is_default: false },
			],
			layout: { header: [], footer: [] },
			strings: { previewBanner: "Draft preview — this page is not published." },
		});
		expect(props.cms.settings["site.name"]).toBe("tbtop demo");
	});

	test("a non-default locale prefix picks the locale; blank translations fall back", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/uk"));
		expect(result.type).toBe("page");
		if (result.type !== "page") return;
		const hero = Object.values(result.props.render.elements).find((element) => element.type === "Hero");
		// subtitle is "" in uk: the English one shows.
		expect(hero?.props).toMatchObject({ title: "Вітаємо в tbtop CMS", subtitle: "This hero block is rendered through json-render." });
		const contact = Object.values(result.props.render.elements).find((element) => element.type === "ContactForm");
		expect(contact?.props).toMatchObject({ heading: "Напишіть нам", submitLabel: "Надіслати" });
		expect(result.props.cms.locale).toBe("uk");
	});

	test("the default locale's prefix 301s to the bare path, keeping the query", async () => {
		const { cms } = await makeCms();
		expect(await cms.loadPage(get("/en/about?x=1"))).toEqual({ type: "redirect", status: 301, location: "/about?x=1" });
	});

	test("an article: breadcrumbs, Article JSON-LD, alternates, title template", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/blog/first-post"));
		if (result.type !== "page") throw new Error(result.type);
		const { props } = result;
		expect(props.breadcrumbs).toEqual([
			{ label: "Home", path: "", url: "/" },
			{ label: "Blog", path: "blog", url: "/blog" },
			{ label: "First post", path: "blog/first-post", url: "/blog/first-post" },
		]);
		expect(props.page.body).toBe("<p>Hello from the first article.</p>");
		const { seo } = props;
		expect(seo).toMatchObject({
			title: "First post — tbtop demo",
			description: "The first article.",
			canonical: "https://site.test/blog/first-post",
			ogType: "article",
			ogLocale: "en_US",
			ogLocaleAlternates: ["uk_UA"],
			articlePublishedTime: "2026-10-01T09:00:00+00:00",
			noindex: false,
			twitterCard: "summary",
			alternates: [
				{ locale: "en", url: "https://site.test/blog/first-post" },
				{ locale: "uk", url: "https://site.test/uk/blog/persii-zapis" },
			],
			xDefault: "https://site.test/blog/first-post",
		});
		expect(seo.jsonLd.map((node) => node["@type"])).toEqual(["WebSite", "BreadcrumbList", "Article", "Organization"]);
		const article = seo.jsonLd[2];
		expect(article).toMatchObject({
			headline: "First post",
			mainEntityOfPage: { "@type": "WebPage", "@id": "https://site.test/blog/first-post" },
			author: { "@type": "Organization", name: "tbtop demo", "@id": "https://site.test#identity" },
		});
		expect(seo.jsonLd[3]).toMatchObject({
			address: { "@type": "PostalAddress", addressLocality: "Kyiv", addressCountry: "UA" },
			telephone: "+380 44 000 00 00",
			email: "hello@example.test",
			sameAs: ["https://github.com/DiVotek"],
		});
	});

	test("a category lists its live children as CollectionPage parts; scheduled ones are hidden", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/blog"));
		if (result.type !== "page") throw new Error(result.type);
		const collection = result.props.seo.jsonLd.find((node) => node["@type"] === "CollectionPage");
		expect(collection?.hasPart).toEqual([{ "@type": "WebPage", name: "First post", url: "https://site.test/blog/first-post" }]);
		expect((await cms.loadPage(get("/blog/coming-soon"))).type).toBe("notFound");
	});

	test("drafts are not found, unless the request may preview; a preview is noindex", async () => {
		const { cms } = await makeCms();
		expect((await cms.loadPage(get("/work-in-progress"))).type).toBe("notFound");
		const { cms: previewing } = await makeCms(undefined, { canPreview: () => true });
		const result = await previewing.loadPage(get("/work-in-progress"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.isPreview).toBe(true);
		expect(result.props.seo.noindex).toBe(true);
		expect(result.headers).toEqual({ "X-Robots-Tag": "noindex" });
	});

	test("a missing path follows its redirect with the query string, else 404s", async () => {
		const { cms } = await makeCms();
		expect(await cms.loadPage(get("/old-about?ref=1"))).toEqual({ type: "redirect", status: 301, location: "/about-us?ref=1" });
		expect(await cms.loadPage(get("/uk/old-about"))).toEqual({ type: "redirect", status: 301, location: "/uk/about-us" });
		const missing = await cms.loadPage(get("/nope"));
		expect(missing).toMatchObject({ type: "notFound", status: 404, locale: "en", path: "nope" });
	});

	test("the AboutPage schema type stored on the page wins over the default", async () => {
		const { cms } = await makeCms();
		const result = await cms.loadPage(get("/about-us"));
		if (result.type !== "page") throw new Error(result.type);
		expect(result.props.seo.jsonLd.map((node) => node["@type"])).toContain("AboutPage");
		expect(result.props.page.body).toBe("<p>We build small, fast sites.</p><p>This page has a body and no blocks.</p>");
	});
});

describe("handle", () => {
	test("robots.txt: the owned group, the sitemap", async () => {
		const { cms } = await makeCms();
		const res = await cms.handle(get("/robots.txt"));
		expect(await res?.text()).toBe("User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /login\n\nSitemap: https://site.test/sitemap.xml\n");
	});

	test("robots.txt with indexing off disallows everything and drops the sitemap", async () => {
		const { cms } = await makeCms();
		await cms.site().settings.set("seo.indexing_enabled", false);
		await cms.site().settings.set("seo.robots_extra", "Disallow: /x\n# keep me\nCrawl-delay: 5");
		expect(await (await cms.handle(get("/robots.txt")))?.text()).toBe("User-agent: *\nDisallow: /\n# keep me\nCrawl-delay: 5\n");
		expect((await cms.handle(get("/sitemap.xml")))?.status).toBe(404);
	});

	test("sitemap index and chunk: live indexable pages with hreflang alternates", async () => {
		const { cms } = await makeCms();
		const index = await (await cms.handle(get("/sitemap.xml")))?.text();
		expect(index).toContain("<loc>https://site.test/sitemap-1.xml</loc>");
		const chunk = (await (await cms.handle(get("/sitemap-1.xml")))?.text()) ?? "";
		const locs = [...chunk.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
		expect(locs).toEqual([
			"https://site.test",
			"https://site.test/uk",
			"https://site.test/about-us",
			"https://site.test/uk/pro-nas",
			"https://site.test/blog",
			"https://site.test/uk/blog",
			"https://site.test/blog/first-post",
			"https://site.test/uk/blog/persii-zapis",
		]);
		expect(chunk).toContain('<xhtml:link rel="alternate" hreflang="x-default" href="https://site.test/about-us"/>');
		expect(chunk).toContain("<priority>1.0</priority>");
		expect((await cms.handle(get("/sitemap-2.xml")))?.status).toBe(404);
		expect(await cms.handle(get("/about-us"))).toBeNull();
	});
});
