import { Database } from "bun:sqlite";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { memoryCache } from "@tbtop/cms";
import { serve } from "@tbtop/cms/serve";
import { admin } from "./admin";
import { fixture } from "./fixture";
import { render, renderError } from "./render";
import { site } from "./site";

// A server host (Bun here): the host gives the database; everything else has a
// default. This file runs on Bun (bun:sqlite, Bun.serve); on Node, pass a
// Kysely over a Node driver (better-sqlite3, pg, mysql2) and serve `app().fetch`
// with any fetch adapter. Secrets from the environment: BETTER_AUTH_SECRET, and
// TBTOP_ADMIN_EMAIL / TBTOP_ADMIN_PASSWORD for the first admin.
// `bun example/site/node.ts`.

export function app(db = new Kysely({ dialect: new BunSqliteDialect({ database: new Database(process.env.DB ?? ":memory:") }) })) {
	return serve(site, {
		db,
		render,
		renderError,
		seed: fixture,
		admin,
		cache: memoryCache(),
		// The rest of the CMS config: url, preview, forms, notifications, timezone…
		config: { timezone: "Europe/Kyiv" },
	});
}

if (import.meta.main) {
	const server = Bun.serve({ port: Number(process.env.PORT ?? 3100), fetch: app().fetch });
	console.log(`http://localhost:${server.port}`);
}
