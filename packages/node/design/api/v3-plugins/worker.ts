import { d1Kysely } from "../kit/d1";
import { workerEntry } from "../kit/worker";
import type { Env } from "../shared/workers";
import { site } from "./node";
import { cloudflare } from "./package";

// V3 · Q16: the same plugin list plus cloudflare(env); the entry builds it per isolate.
export default workerEntry((env: Env) => {
	const app = site(d1Kysely(env.DB), [cloudflare(env, { mediaUrl: "https://media.example.com" })]);
	return { boot: app.boot, fetch: (request) => app.fetch(request), scheduled: () => app.cms.tasks.scheduled() };
});
