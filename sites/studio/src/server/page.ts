import { createServerFn } from "@tanstack/react-start";
import { getRequest } from "@tanstack/react-start/server";
import type { ErrorPageProps, PageProps } from "@tbtop/cms";
import { loadPageAt, type LoadedPage } from "./loadPage";

export type CmsView = PageProps | ErrorPageProps;

/** Client navigations: rebuild the CMS page from a path on the server. */
export const loadCmsPage = createServerFn({ method: "GET" })
	.validator((path: string) => path)
	.handler(async ({ data: path }): Promise<LoadedPage | ErrorPageProps> => {
		const caller = getRequest();
		const base = process.env.CMS_URL ?? (caller.url.startsWith("http") ? new URL(caller.url).origin : "http://127.0.0.1:3300");
		const url = new URL(path.startsWith("http") ? path : path.startsWith("/") ? path : `/${path}`, base);
		const outcome = await loadPageAt(url.pathname, url.search, base);
		if (outcome.kind === "redirect") {
			throw new Response(null, { status: outcome.status, headers: { Location: outcome.location } });
		}
		if (outcome.kind === "notFound") {
			return outcome.props;
		}
		return outcome.props;
	});
