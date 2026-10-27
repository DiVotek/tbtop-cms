import { cloudflare } from "@tbtop/cms/cloudflare";
import { admin } from "./admin";
import { fixture } from "./fixture";
import { render, renderError } from "./render";
import { site } from "./site";

// Cloudflare Workers: the bindings by name (wrangler.jsonc), nothing passed.
// Migrations, the seed and the first admin run on an isolate's first request.
export default cloudflare(site, { render, renderError, seed: fixture, admin, config: { timezone: "Europe/Kyiv" } });
