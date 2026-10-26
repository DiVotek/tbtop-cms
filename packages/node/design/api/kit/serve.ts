import { type AdminConfig, createAdmin, type Disk, memoryDisk, rootView } from "@tbtop/admin";
import type { Kysely } from "kysely";
import { type Cms, migrateCms } from "../../../src";
import type { CmsAdminOptions } from "../../../src/admin/options";
import type { DefaultAuth } from "./auth";
import { dialectOf } from "./dialect";

export type AdminDefaults = Omit<Partial<AdminConfig>, "pages" | "disks"> & CmsAdminOptions & { disks?: Record<string, Disk> };

/**
 * Q15's `cms.admin.create()`: createAdmin({ ...cms.admin.options(), ... })
 * with what a host otherwise always writes: a `public` disk and a root view
 * without the admin client assets. Without a disk, uploads go to memory and
 * vanish on restart, so that default says so once.
 */
export function createCmsAdmin(cms: Cms, options: AdminDefaults = {}) {
	const { pages, disks, ...rest } = options;
	return createAdmin({
		...cms.admin.options(pages === undefined ? {} : { pages }),
		disks: disks ?? memoryFallback(),
		rootView: rest.rootView ?? rootView(),
		...rest,
	});
}

/** Everything that is not a page, in one handler: CMS routes, Better Auth's API, the admin panel. Null: a page request. */
export function cmsRoutes(cms: Cms, admin: { handle(request: Request): Promise<Response | null> }, auth: DefaultAuth | null) {
	return async (request: Request): Promise<Response | null> =>
		(await cms.handle(request)) ?? (auth === null ? null : await auth.handle(request)) ?? (await admin.handle(request));
}

/** The CMS's tables, then Better Auth's: a deploy step, or the first request on Workers. Idempotent. */
export async function migrateAll(db: Kysely<unknown>, auth: DefaultAuth | null): Promise<void> {
	await migrateCms(db, { dialect: dialectOf(db), userKey: auth === null ? "int" : "string" });
	await auth?.migrate();
}

/** A page request to a Response with the host's renderer: redirect, 404 and page as the package answers them; a Response from the renderer passes through. */
export async function renderPage<P>(
	cms: { loadPage(request: Request): Promise<import("../../../src").LoadResult<P>> },
	request: Request,
	render: (props: P) => string | Response,
): Promise<Response> {
	const result = await cms.loadPage(request);
	if (result.type === "redirect") {
		return new Response(null, { status: result.status, headers: { location: result.location } });
	}
	if (result.type === "notFound") {
		return new Response("Not found", { status: 404 });
	}
	const out = render(result.props);
	if (out instanceof Response) {
		return out;
	}
	return new Response(out, { headers: { "content-type": "text/html; charset=utf-8", ...result.headers } });
}

function memoryFallback(): Record<string, Disk> {
	console.warn("@tbtop/cms: no `disk` given, uploads are kept in memory and lost on restart");
	return { public: memoryDisk("/storage") };
}
