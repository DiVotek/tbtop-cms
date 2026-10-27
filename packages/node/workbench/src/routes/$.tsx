import { JSONUIProvider } from "@json-render/react";
import { createFileRoute, Link } from "@tanstack/react-router";
import type { ErrorPageProps } from "@tbtop/cms";
import { CmsHead, CmsRenderer, seoHead } from "@tbtop/cms/react";
import { catalog, registry } from "../blocks/registry";
import { site } from "../server/cms";
import { type CmsView, loadCmsPage } from "../server/page";

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
	component: () => <CmsPage {...Route.useLoaderData()} />,
	// A 404 carries the editor's 4xx page (ErrorPageProps); its noindex head comes from CmsHead, which React hoists.
	notFoundComponent: ({ data }) => {
		const props = data as ErrorPageProps | undefined;
		return props === undefined ? <h1>Not found</h1> : (
			<>
				<CmsHead seo={props.seo} />
				<CmsPage {...props} />
			</>
		);
	},
});

function CmsPage({ page, render, breadcrumbs, isPreview, cms: shared }: CmsView) {
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
				<h1>{page.heading ?? page.name}</h1>
				{/* richtextHtml output: the Lexical allowlist, already escaped. */}
				<div data-testid="page-body" dangerouslySetInnerHTML={{ __html: page.body }} />
				<JSONUIProvider registry={registry}>
					<CmsRenderer spec={render} catalog={catalog} registry={registry} showErrors={isPreview} />
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
