import type { ExecutionContext, ScheduledController } from "../shared/workers";

/** What one isolate keeps per `env`: the built app and its first-request boot (migrations, seed). */
export type WorkerApp = {
	boot(): Promise<void>;
	fetch(request: Request, ctx: ExecutionContext): Promise<Response>;
	scheduled(): Promise<unknown>;
};

/**
 * The Workers module shape `{ fetch, scheduled }` around an app built from
 * `env` once per isolate. Bindings exist only inside a handler, so the app
 * cannot be built at module scope as on Node; the first request (or cron
 * tick) awaits the boot, the rest reuse it. Cached per `env` object: workerd
 * hands one isolate the same `env` on every call, so that is once per
 * isolate; a runtime that passes a fresh `env` re-runs the boot, which is
 * idempotent (migrations, seed on an empty site, ensure) but slower.
 */
export function workerEntry<E extends object>(make: (env: E) => WorkerApp) {
	const apps = new WeakMap<E, { app: WorkerApp; ready: Promise<void> }>();
	const get = (env: E) => {
		let hit = apps.get(env);
		if (hit === undefined) {
			const app = make(env);
			// A failed boot (D1 hiccup mid-migration) is dropped, so the next request retries it.
			const ready = app.boot().catch((error: unknown) => {
				apps.delete(env);
				throw error;
			});
			hit = { app, ready };
			apps.set(env, hit);
		}
		return hit;
	};
	return {
		async fetch(request: Request, env: E, ctx: ExecutionContext): Promise<Response> {
			const { app, ready } = get(env);
			await ready;
			return app.fetch(request, ctx);
		},
		async scheduled(_controller: ScheduledController, env: E, ctx: ExecutionContext): Promise<void> {
			const { app, ready } = get(env);
			ctx.waitUntil(ready.then(() => app.scheduled()));
		},
	};
}
