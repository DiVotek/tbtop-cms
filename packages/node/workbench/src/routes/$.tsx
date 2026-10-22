import { JSONUIProvider, Renderer } from "@json-render/react";
import { createFileRoute, Link } from "@tanstack/react-router";
import type { PageProps } from "@tbtop/cms";
import { registry } from "../blocks/registry";
import { site } from "../server/cms";
import { loadCmsPage } from "../server/page";

export const Route = createFileRoute("/$")({
	// robots.txt, the sitemaps and form posts (/_cms/forms/...) are plain
	// responses; every other URL is a page.
	server: {
		handlers: {
			GET: async ({ request, next }) => (await (await site()).cms.handle(request)) ?? next(),
			POST: async ({ request, next }) => (await (await site()).cms.handle(request)) ?? next(),
		},
	},
	loader: ({ location }) => loadCmsPage({ data: location.href }),
	head: ({ loaderData }) => (loaderData === undefined ? {} : seoHead(loaderData.seo)),
	component: CmsPage,
});

function seoHead(seo: PageProps["seo"]) {
	return {
		meta: [
			{ title: seo.title },
			{ name: "description", content: seo.description },
			...(seo.noindex ? [{ name: "robots", content: "noindex" }] : []),
			{ property: "og:title", content: seo.ogTitle },
			{ property: "og:description", content: seo.ogDescription },
			{ property: "og:type", content: seo.ogType },
			{ property: "og:url", content: seo.ogUrl },
			...(seo.ogImage === null ? [] : [{ property: "og:image", content: seo.ogImage }]),
			...(seo.ogLocale === null ? [] : [{ property: "og:locale", content: seo.ogLocale }]),
			{ name: "twitter:card", content: seo.twitterCard },
		],
		links: [
			{ rel: "canonical", href: seo.canonical },
			...seo.alternates.map((alternate) => ({ rel: "alternate", hrefLang: alternate.locale, href: alternate.url })),
			...(seo.xDefault === null ? [] : [{ rel: "alternate", hrefLang: "x-default", href: seo.xDefault }]),
		],
		scripts: seo.jsonLd.map((node) => ({ type: "application/ld+json", children: jsonLd(node) })),
	};
}

/** The JSON with `<`, `>`, `&` escaped (JSON_HEX_TAG | JSON_HEX_AMP): the router writes it as raw HTML. */
const jsonLd = (node: unknown) => JSON.stringify(node).replace(/[<>&]/g, (c) => `\\u${c.charCodeAt(0).toString(16).padStart(4, "0")}`);

function CmsPage() {
	const { page, render, breadcrumbs, isPreview, cms: shared }: PageProps = Route.useLoaderData();
	return (
		<>
			{isPreview ? <div data-testid="preview-banner">{shared.strings.previewBanner}</div> : null}
			<nav aria-label="Languages">
				{shared.languages.map((language) => (
					<PageLink key={language.locale} href={language.is_default ? "/" : `/${language.locale}`} lang={language.locale}>
						{language.name}
					</PageLink>
				))}
			</nav>
			<nav aria-label="Breadcrumbs">
				{breadcrumbs.map((crumb) => (
					<PageLink key={crumb.url} href={crumb.url}>
						{crumb.label}
					</PageLink>
				))}
			</nav>
			<main>
				<h1>{page.heading ?? page.title ?? page.name}</h1>
				{/* richtextHtml output: the Lexical allowlist, already escaped. */}
				<div data-testid="page-body" dangerouslySetInnerHTML={{ __html: page.body }} />
				<JSONUIProvider registry={registry}>
					<Renderer spec={render} registry={registry} />
				</JSONUIProvider>
			</main>
		</>
	);
}

/** A CMS URL as a client-side navigation: the loader fetches the next page through the server function. */
function PageLink({ href, lang, children }: { href: string; lang?: string; children: string }) {
	const path = new URL(href, "http://site").pathname;
	return (
		<Link to="/$" params={{ _splat: path.slice(1) }} lang={lang}>
			{children}
		</Link>
	);
}
