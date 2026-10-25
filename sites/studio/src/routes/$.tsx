// @ts-nocheck — TanStack Start route `server` handlers match the workbench; types lag the runtime API.
import { JSONUIProvider } from "@json-render/react";
import { createFileRoute, Link, notFound, redirect } from "@tanstack/react-router";
import { assembleSpec, type ErrorPageProps, type PageProps, type ResolvedBlock } from "@tbtop/cms";
import { CmsHead, CmsRenderer, seoHead } from "@tbtop/cms/react";
import { ArticleMeta } from "../components/ArticleMeta";
import { JournalIndexView } from "../components/JournalIndex";
import { ThemeToggle } from "../components/ThemeToggle";
import { catalog, registry } from "../blocks/registry";
import { rssXml } from "../server/feed";
import { site } from "../server/cms";
import { loadStudioPage, type LoadedPage } from "../server/loadPage";

export const Route = createFileRoute("/$")({
	server: {
		handlers: {
			GET: async ({ request, next }) => {
				const url = new URL(request.url);
				if (url.pathname === "/feed.xml") {
					const xml = await rssXml(url.href);
					return new Response(xml, { headers: { "content-type": "application/rss+xml; charset=utf-8" } });
				}
				return (await (await site()).cms.handle(request)) ?? next();
			},
			POST: async ({ request, next }) => (await (await site()).cms.handle(request)) ?? next(),
		},
	},
	loader: async ({ location }) => {
		const search = location.searchStr ?? (typeof location.search === "string" ? location.search : "");
		const props = await loadStudioPage({ data: { pathname: location.pathname, search } });
		if (props && typeof props === "object" && "__kind" in props && props.__kind === "redirect") {
			throw redirect({ href: props.location, statusCode: props.status });
		}
		if ("status" in props && props.status !== 200) {
			throw notFound({ data: props });
		}
		return props as LoadedPage;
	},
	head: ({ loaderData }) => (loaderData === undefined ? {} : seoHead(loaderData.seo)),
	component: () => <CmsPage {...(Route.useLoaderData() as LoadedPage)} />,
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

type LoaderView = PageProps & { journalIndex?: Awaited<ReturnType<typeof loadJournalIndex>> };

function CmsPage(props: LoaderView | ErrorPageProps) {
	const layout = props.cms.layout;
	const isPreview = "isPreview" in props ? props.isPreview : false;
	const isError = "status" in props && props.status !== 200;
	return (
		<>
			{"seo" in props ? <CmsHead seo={props.seo} /> : null}
			{isPreview ? <div className="bg-amber-500 px-4 py-2 text-center text-sm font-medium text-black">{props.cms.strings.previewBanner}</div> : null}
			<div className="flex min-h-dvh flex-col">
				<LayoutBlocks blocks={layout.header} isPreview={!!isPreview} />
				<div className="lumen-container flex justify-end py-2">
					<nav aria-label="Languages" className="flex flex-wrap items-center gap-3 text-sm">
						{props.cms.languages.map((language) => (
							<PageLink key={language.locale} href={language.is_default ? "/" : `/${language.locale}`} lang={language.locale}>
								{language.name}
							</PageLink>
						))}
						<ThemeToggle />
					</nav>
				</div>
				<main className="flex-1">
					{isError ? (
						<div className="lumen-container py-16">
							<h1 className="font-display text-3xl font-semibold">{props.page.heading ?? props.page.title}</h1>
						</div>
					) : (
						<>
							<header className="lumen-container pb-6 pt-4">
								<nav aria-label="Breadcrumbs" className="mb-4 flex flex-wrap gap-2 text-sm text-lumen-muted">
									{props.breadcrumbs.map((crumb) => (
										<PageLink key={crumb.url} href={crumb.url}>
											{crumb.label}
										</PageLink>
									))}
								</nav>
								<h1 className="font-display text-3xl font-semibold sm:text-4xl">{props.page.heading ?? props.page.title ?? props.page.name}</h1>
								{props.page.summary ? <p className="mt-3 max-w-2xl text-lg text-lumen-muted">{props.page.summary}</p> : null}
							</header>
							<ArticleMeta page={props.page} />
							{"journalIndex" in props && props.journalIndex ? (
								<JournalIndexView index={props.journalIndex} basePath={windowPath(props.path, props.cms.locale, props.cms.defaultLocale)} />
							) : null}
							{props.page.body ? <div className="lumen-container lumen-prose max-w-3xl pb-8" data-testid="page-body" dangerouslySetInnerHTML={{ __html: props.page.body }} /> : null}
							<JSONUIProvider registry={registry}>
								<CmsRenderer spec={props.render} catalog={catalog} registry={registry} showErrors={!!isPreview} />
							</JSONUIProvider>
						</>
					)}
				</main>
				<LayoutBlocks blocks={layout.footer} isPreview={!!isPreview} />
			</div>
		</>
	);
}

function LayoutBlocks({ blocks, isPreview }: { blocks: ResolvedBlock[]; isPreview: boolean }) {
	if (blocks.length === 0) {
		return null;
	}
	return (
		<JSONUIProvider registry={registry}>
			<CmsRenderer spec={assembleSpec(blocks)} catalog={catalog} registry={registry} showErrors={isPreview} />
		</JSONUIProvider>
	);
}

function windowPath(path: string, locale: string, defaultLocale: string): string {
	const prefix = locale === defaultLocale ? "" : `/${locale}`;
	const rest = path ? `/${path}` : "";
	return `${prefix}${rest}` || "/";
}

function PageLink({ href, lang, children }: { href: string; lang?: string; children: string }) {
	const path = new URL(href, "http://site").pathname;
	return (
		<Link to="/$" params={{ _splat: path === "/" ? "" : path.slice(1) }} lang={lang}>
		 {children}
		</Link>
	);
}
