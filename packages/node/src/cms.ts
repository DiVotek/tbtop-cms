import { adminPages, type CmsAdmin } from "./admin";
import type { CmsConfig } from "./config";
import { type PageTypeDef, PageTypes } from "./content/pageTypes";
import { notifySubmission, retrySubmission } from "./forms/notifier";
import { submitForm } from "./forms/submit";
import { submitThrottle } from "./forms/throttle";
import { type ErrorPageProps, errorPage } from "./frontend/errorPage";
import { type LoadResult, loadPage, type PageProps } from "./frontend/load";
import { hasValidPreviewSignature } from "./frontend/previewLink";
import { robotsTxt } from "./seo/robots";
import { SchemaTypes } from "./seo/schemaTypes";
import { chunkCount, sitemapChunkXml, sitemapIndexXml } from "./seo/sitemap";
import { indexingEnabled } from "./seo/resolver";
import { Site } from "./site";

/**
 * `T`: the host's page types, which type `page.fields` in loadPage()'s props
 * per `page.type`. A bare `Cms` takes any CMS (its props are untyped JSON
 * fields): the props type is not covariant in `T`, so the default is `any`.
 */
// biome-ignore lint/suspicious/noExplicitAny: the bare Cms accepts a Cms of any page types.
export type Cms<T extends readonly PageTypeDef[] = any> = {
	readonly config: CmsConfig<T>;
	readonly pageTypes: PageTypes;
	readonly schemaTypes: SchemaTypes;
	/** A fresh per-request view of the stores; the origin comes from `url` or the request. */
	site(request?: Request): Site;
	/**
	 * The page at the request URL: props for the host to render, a redirect, or
	 * not found. A miss is counted in the 404 log; pass `waitUntil` (Workers'
	 * `ctx.waitUntil`) to write it after the answer instead of before.
	 */
	loadPage(request: Request, options?: { waitUntil?: (task: Promise<unknown>) => void }): Promise<LoadResult<PageProps<T>>>;
	/** robots.txt, sitemap.xml and its chunks, and form posts to /_cms/forms/...; null for every other URL. */
	handle(request: Request): Promise<Response | null>;
	/**
	 * Props for an error answer at the request URL (ErrorResponse): the live
	 * error page of the status class (4xx/5xx) or a fallback. For a 5xx it never
	 * throws: with the database down it answers props built without it.
	 */
	errorPage(request: Request, status: number): Promise<ErrorPageProps>;
	/** Background work for a cron, `ctx.waitUntil()` or a queue: the same functions the `tbtop-cms` CLI runs. */
	readonly tasks: CmsTasks;
	/** Admin pages and nav groups to spread into the host's createAdmin(). */
	readonly admin: CmsAdmin;
};

export type CmsTasks = {
	/**
	 * Delivers pending submissions (`forms.delivery: "outbox"`) and retries
	 * the ones a dead worker left processing. Returns how many it took.
	 */
	deliver(options?: { limit?: number }): Promise<number>;
	/** Deletes 404 log rows not seen within `seo.not_found.retention_days` (default 90); how many went. */
	pruneNotFound(): Promise<number>;
};

const SITEMAP_CHUNK = /^\/sitemap-([1-9][0-9]{0,8})\.xml$/;

export function createCms<const T extends readonly PageTypeDef[] = []>(config: CmsConfig<T>): Cms<T> {
	const pageTypes = new PageTypes(config.pageTypes);
	const schemaTypes = new SchemaTypes(config.schemaTypes);
	const throttle = submitThrottle(config.forms);
	const site = (request?: Request): Site => {
		const origin = (config.url ?? (request === undefined ? "http://localhost" : new URL(request.url).origin)).replace(/\/+$/, "");
		return new Site(config, pageTypes, schemaTypes, origin);
	};
	const cms: Cms<T> = {
		config,
		pageTypes,
		schemaTypes,
		site,
		// The props match T only as far as the page types' schemas checked them: resolved fields that fail one are dropped.
		loadPage: async (request, { waitUntil } = {}) => {
			const scope = site(request);
			// An editor (canPreview) or a signed preview link (PageController::hasPreviewAccess).
			const preview = (await config.canPreview?.(request)) === true || (await hasValidPreviewSignature(scope, request));
			return (await loadPage(scope, request, { preview, ...(waitUntil === undefined ? {} : { waitUntil }) })) as LoadResult<PageProps<T>>;
		},
		handle: async (request) => {
			if (request.method === "POST") {
				return submitForm(site(request), request, throttle, cms.admin.hostRules);
			}
			if (request.method !== "GET" && request.method !== "HEAD") {
				return null;
			}
			const { pathname } = new URL(request.url);
			const scope = site(request);
			if (pathname === "/robots.txt") {
				return new Response(await robotsTxt(scope), { headers: { "Content-Type": "text/plain" } });
			}
			if (config.sitemap === false || (pathname !== "/sitemap.xml" && !SITEMAP_CHUNK.test(pathname))) {
				return null;
			}
			if (!(await indexingEnabled(scope))) {
				return new Response(null, { status: 404 });
			}
			if (pathname === "/sitemap.xml") {
				return xml(await sitemapIndexXml(scope));
			}
			const chunk = Number(SITEMAP_CHUNK.exec(pathname)?.[1]);
			return chunk > (await chunkCount(scope)) ? new Response(null, { status: 404 }) : xml(await sitemapChunkXml(scope, chunk));
		},
		errorPage: (request, status) => errorPage(site(request), request, status),
		tasks: {
			deliver: async ({ limit = 50 } = {}) => {
				const scope = site();
				const ids = await scope.submissions.due(limit);
				for (const id of ids) {
					const submission = await scope.submissions.find(id);
					if (submission === null) {
						continue;
					}
					await (submission.status === "pending" ? notifySubmission(scope, submission) : retrySubmission(scope, submission));
				}
				return ids.length;
			},
			pruneNotFound: () => site().notFound.prune(),
		},
		admin: undefined as unknown as CmsAdmin,
	};
	(cms as { admin: CmsAdmin }).admin = adminPages(cms);
	return cms;
}

const xml = (body: string) => new Response(body, { headers: { "Content-Type": "application/xml" } });
