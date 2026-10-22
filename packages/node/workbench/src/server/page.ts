import { notFound, redirect } from "@tanstack/react-router";
import { createServerFn } from "@tanstack/react-start";
import { getRequest, setResponseHeaders } from "@tanstack/react-start/server";
import { site } from "./cms";

/**
 * The CMS page at a path. A server function runs on its own URL during client
 * navigation, so the page request is rebuilt from the path, keeping the
 * caller's headers (cookies, Accept-Language).
 */
export const loadCmsPage = createServerFn({ method: "GET" })
	.validator((path: string) => path)
	.handler(async ({ data: path }) => {
		const caller = getRequest();
		const { cms } = await site();
		const result = await cms.loadPage(new Request(new URL(path, caller.url), { headers: caller.headers }));
		if (result.type === "redirect") {
			throw redirect({ href: result.location, statusCode: result.status });
		}
		if (result.type === "notFound") {
			throw notFound();
		}
		setResponseHeaders(new Headers(result.headers));
		return result.props;
	});
