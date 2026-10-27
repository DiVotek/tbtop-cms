import type { Kysely } from "kysely";
import type { DefaultAuth } from "../betterAuth/defaultAuth";
import { migrateCms } from "../db/migrations";
import type { ErrorPageProps } from "../frontend/errorPage";
import type { LoadResult } from "../frontend/load";

type Handler = { handle(request: Request): Promise<Response | null> };

/** Everything that is not a page, in one handler: the CMS's routes, Better Auth's API, the admin panel. Null: a page request. */
export function cmsRoutes(cms: Handler, admin: Handler, auth: Pick<DefaultAuth, "handle"> | null = null) {
	return async (request: Request): Promise<Response | null> =>
		(await cms.handle(request)) ?? (auth === null ? null : await auth.handle(request)) ?? (await admin.handle(request));
}

/** The CMS's tables (user columns as Better Auth's string ids when it is on), then Better Auth's. Idempotent: a deploy step, or the first request. */
export async function migrateAll(db: Kysely<unknown>, auth: Pick<DefaultAuth, "migrate"> | null = null, userKey: "int" | "string" = "int"): Promise<void> {
	await migrateCms(db, { userKey: auth === null ? userKey : "string" });
	await auth?.migrate();
}

/** A page's HTML (or the host's own Response); the error page's, given a renderer for it. */
export type PageRenderers<P> = {
	render: (props: P) => string | Response | Promise<string | Response>;
	renderError?: (props: ErrorPageProps) => string | Response | Promise<string | Response>;
};

/** A page request to a Response: redirects and 404s as the CMS answers them, pages through the host's renderer. */
export async function renderPage<P>(
	cms: { loadPage(request: Request, options?: { waitUntil?: (task: Promise<unknown>) => void }): Promise<LoadResult<P>> },
	request: Request,
	renderers: PageRenderers<P>,
	waitUntil?: (task: Promise<unknown>) => void,
): Promise<Response> {
	const result = await cms.loadPage(request, waitUntil === undefined ? {} : { waitUntil });
	if (result.type === "redirect") {
		return new Response(null, { status: result.status, headers: { location: result.location } });
	}
	if (result.type === "notFound") {
		const out = renderers.renderError === undefined ? "Not found" : await renderers.renderError(result.props);
		return out instanceof Response ? out : html(out, 404, {});
	}
	const out = await renderers.render(result.props);
	return out instanceof Response ? out : html(out, 200, result.headers);
}

const html = (body: string, status: number, headers: Record<string, string>) =>
	new Response(body, { status, headers: { "content-type": "text/html; charset=utf-8", ...headers } });
