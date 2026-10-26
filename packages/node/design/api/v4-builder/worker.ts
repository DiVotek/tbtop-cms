import { runtimeFromEnv } from "../kit/cloudflare";
import { workerEntry } from "../kit/worker";
import type { Env } from "../shared/workers";
import { app as site } from "./node";

// V4 · Q16: the builder plus the runtime read from bindings (`@tbtop/cms/cloudflare`).
export default workerEntry((env: Env) => {
	const app = site(runtimeFromEnv(env, { mediaUrl: "https://media.example.com" }));
	return { boot: app.boot, fetch: (request) => app.fetch(request), scheduled: () => app.cms.tasks.scheduled() };
});
