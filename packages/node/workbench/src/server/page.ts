import { notFound, redirect } from "@tanstack/react-router";
import { createServerFn } from "@tanstack/react-start";
import { getRequest, setResponseHeaders, setResponseStatus } from "@tanstack/react-start/server";
import type { ErrorPageProps, PageProps } from "@tbtop/cms";
import { site } from "./cms";

/** What the page route renders: a live page, or the editor's 5xx page when loading failed. */
export type CmsView = PageProps | ErrorPageProps;

/**
 * The CMS page at a path. A server function runs on its own URL during client
 * navigation, so the page request is rebuilt from the path, keeping the
 * caller's headers (cookies, Accept-Language). A 404 throws notFound with
 * the error page's props (routes/$.tsx renders them); a failure answers 500
 * with the 5xx error page's.
 */
export const loadCmsPage = createServerFn({ method: "GET" })
	.validator((path: string) => path)
	.handler(async ({ data: path }): Promise<CmsView> => {
		const caller = getRequest();
		const { cms } = await site();
		const request = new Request(new URL(path, caller.url), { headers: caller.headers });
		let result: Awaited<ReturnType<typeof cms.loadPage>>;
		try {
			result = await cms.loadPage(request);
		} catch (error) {
			console.error(error);
			setResponseStatus(500);
			return cms.errorPage(request, 500);
		}
		if (result.type === "redirect") {
			throw redirect({ href: result.location, statusCode: result.status });
		}
		if (result.type === "notFound") {
			setResponseStatus(404);
			throw notFound({ data: result.props });
		}
		setResponseHeaders(new Headers(result.headers));
		return result.props;
	});
