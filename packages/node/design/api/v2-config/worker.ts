import { runtimeFromEnv } from "../kit/cloudflare";
import { workerEntry } from "../kit/worker";
import type { Env } from "../shared/workers";
import { site } from "./node";

// V2 · Q16: the config plus the runtime read from bindings (`@tbtop/cms/cloudflare`).
export default workerEntry((env: Env) => {
	const app = site(runtimeFromEnv(env, { mediaUrl: "https://media.example.com" }));
	return { boot: app.boot, fetch: (request) => app.fetch(request), scheduled: () => app.cms.tasks.scheduled() };
});
