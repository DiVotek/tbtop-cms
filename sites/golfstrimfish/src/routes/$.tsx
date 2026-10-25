import { JSONUIProvider } from "@json-render/react";
import { createFileRoute, Link } from "@tanstack/react-router";
import type { ErrorPageProps } from "@tbtop/cms";
import { assembleSpec } from "@tbtop/cms";
import { CmsHead, CmsRenderer, seoHead } from "@tbtop/cms/react";
import { catalog, registry } from "../blocks/registry";
import { site } from "../server/cms";
import { type CmsView, loadCmsPage } from "../server/page";

export const Route = createFileRoute("/$")({
	server: {
		handlers: {
			GET: async ({ request, next }) => (await (await site()).cms.handle(request)) ?? next(),
			POST: async ({ request, next }) => (await (await site()).cms.handle(request)) ?? next(),
		},
	},
	loader: ({ location }) => loadCmsPage({ data: location.href }),
	head: ({ loaderData }) => (loaderData === undefined ? {} : seoHead(loaderData.seo)),
	component: () => <CmsPage {...Route.useLoaderData()} />,
	notFoundComponent: ({ data }) => {
		const props = data as ErrorPageProps | undefined;
		return props === undefined ? <h1>Сторінку не знайдено</h1> : (
			<>
				<CmsHead seo={props.seo} />
				<CmsPage {...props} />
			</>
		);
	},
});

function CmsPage(props: CmsView) {
	const { page, render, breadcrumbs, isPreview, cms: shared } = props;
	const headerSpec = assembleSpec(shared.layout.header);
	const footerSpec = assembleSpec(shared.layout.footer);

	return (
		<>
			<CmsHead seo={props.seo} />
			{isPreview ? <div className="bg-amber-100 px-4 py-2 text-center text-sm text-amber-950">{shared.strings.previewBanner}</div> : null}
			<JSONUIProvider registry={registry}>
				<CmsRenderer spec={headerSpec} catalog={catalog} registry={registry} showErrors={isPreview} />
			</JSONUIProvider>
			{breadcrumbs.length > 1 ? (
				<nav className="mx-auto max-w-6xl px-4 py-3 text-sm text-ocean-800/70 md:px-6" aria-label="Breadcrumbs">
					{breadcrumbs.map((crumb, index) => (
						<span key={crumb.url}>
							{index > 0 ? <span className="mx-2">/</span> : null}
							{index === breadcrumbs.length - 1 ? (
								<span aria-current="page">{crumb.label}</span>
							) : (
								<PageLink href={crumb.url}>{crumb.label}</PageLink>
							)}
						</span>
					))}
				</nav>
			) : null}
			<main>
				{page.heading && page.type !== "article" ? (
					<div className="mx-auto max-w-6xl px-4 pt-8 md:px-6">
						<h1 className="text-3xl font-semibold tracking-tight text-ocean-900 md:text-4xl">{page.heading}</h1>
						{page.summary ? <p className="mt-2 max-w-2xl text-ocean-800/80">{page.summary}</p> : null}
					</div>
				) : null}
				{page.body ? (
					<div className="cms-prose mx-auto max-w-3xl px-4 py-8 md:px-6" data-testid="page-body" dangerouslySetInnerHTML={{ __html: page.body }} />
				) : null}
				<JSONUIProvider registry={registry}>
					<CmsRenderer spec={render} catalog={catalog} registry={registry} showErrors={isPreview} />
				</JSONUIProvider>
			</main>
			<JSONUIProvider registry={registry}>
				<CmsRenderer spec={footerSpec} catalog={catalog} registry={registry} showErrors={isPreview} />
			</JSONUIProvider>
		</>
	);
}

function PageLink({ href, children }: { href: string; children: string }) {
	const path = new URL(href, "http://site").pathname;
	return (
		<Link to="/$" params={{ _splat: path.slice(1) }} className="hover:text-ocean-600 hover:underline">
			{children}
		</Link>
	);
}
