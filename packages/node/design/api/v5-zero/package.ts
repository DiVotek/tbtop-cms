import { type Cms, type Fixture, type PageProps, seedFixtures } from "../../../src";
import { runtimeFromEnv } from "../kit/cloudflare";
import { renderPage } from "../kit/serve";
import { workerEntry } from "../kit/worker";
import type { Env } from "../shared/workers";
import type { Runtime } from "../v2-config/package";
import { createCms as createFromBuilder } from "../v4-builder/package";
import type { Site } from "./define";

type Options<S extends Site> = {
	/** The host's page renderer (the framework's job); without it, `fetch` answers 404 for a page, and a host with its own router uses `handle`, which answers null there. */
	render?: (props: PageProps<S["pageTypes"]>) => string | Response;
	/** Seeded once, on an empty site. */
	seed?: Fixture;
	/** The first admin, ensured on boot; default TBTOP_ADMIN_EMAIL / TBTOP_ADMIN_PASSWORD. */
	admin?: { email: string; password: string };
};

const envAdmin = () => {
	const email = typeof process === "undefined" ? undefined : process.env.TBTOP_ADMIN_EMAIL;
	const password = typeof process === "undefined" ? undefined : process.env.TBTOP_ADMIN_PASSWORD;
	return email && password ? { email, password } : undefined;
};

function app<S extends Site>(site: S, runtime: Runtime, options: Options<S>) {
	const cms = createFromBuilder(site as never, runtime) as unknown as ReturnType<typeof createFromBuilder> & Cms<S["pageTypes"]>;
	const admin = options.admin ?? envAdmin();
	const boot = async () => {
		await cms.migrate();
		if (options.seed !== undefined) await seedFixtures(cms, options.seed);
		if (admin !== undefined) await cms.users.ensure(admin.email, "admin", admin);
	};
	const render = options.render;
	const fetch = async (request: Request): Promise<Response> => {
		const routed = await cms.route(request);
		if (routed !== null) return routed;
		if (render === undefined) return new Response(null, { status: 404 });
		return renderPage(cms, request, (props) => render(props as PageProps<S["pageTypes"]>));
	};
	return { cms, boot, fetch };
}

/** Node / Bun: `export default serve(site, { db })` gives `{ fetch }` for Bun.serve, Hono, a Start server entry. Boots on the first request. */
export function serve<S extends Site>(site: S, options: Options<S> & Runtime) {
	const { render, seed, admin, ...runtime } = options;
	const built = app(site, runtime, { ...(render === undefined ? {} : { render }), ...(seed === undefined ? {} : { seed }), ...(admin === undefined ? {} : { admin }) });
	let booting: Promise<void> | null = null;
	// A failed boot is forgotten, so the next request retries it instead of rethrowing forever.
	const ready = () =>
		(booting ??= built.boot().catch((error: unknown) => {
			booting = null;
			throw error;
		}));
	return {
		cms: built.cms,
		ready,
		/** Everything but pages (admin, auth, robots, sitemaps, forms); null for a page: a TanStack Start entry falls through to its router. */
		handle: async (request: Request) => {
			await ready();
			return built.cms.route(request);
		},
		fetch: async (request: Request) => {
			await ready();
			return built.fetch(request);
		},
	};
}

/**
 * Q16 · `export default cloudflare(site)`: bindings by name (DB: D1, CACHE:
 * KV, MEDIA: R2 + MEDIA_URL, BETTER_AUTH_SECRET), migrations and the seed on
 * the first request of an isolate, the cron's `scheduled()` wired.
 */
export function cloudflare<S extends Site>(site: S, options: Options<S> & { mediaUrl?: string } = {}) {
	const { mediaUrl, ...rest } = options;
	return workerEntry((env: Env) => {
		const built = app(site, runtimeFromEnv(env, mediaUrl === undefined ? {} : { mediaUrl }), rest);
		return { boot: built.boot, fetch: (request) => built.fetch(request), scheduled: () => built.cms.tasks.scheduled() };
	});
}
