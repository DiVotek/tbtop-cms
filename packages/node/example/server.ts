/**
 * A minimal host on Bun: SQLite file, the demo fixture, the CMS admin and a
 * bare HTML frontend. `bun example/server.ts`, then open http://localhost:3100.
 * The admin answers Inertia JSON here; it needs the @tbtop/inertia-admin
 * client (see tbtop's apps/node-demo) to render in a browser.
 */
import { Database } from "bun:sqlite";
import { createAdmin } from "@tbtop/admin";
import { kyselyDriver, kyselyRules } from "@tbtop/admin/kysely";
import { Kysely } from "kysely";
import { BunSqliteDialect } from "kysely-bun-sqlite";
import { type CmsDatabase, cmsBlockImpls, cmsBlocks, createCms, demoFixture, implementBlocks, migrateCms, type PageProps, seedFixtures } from "../src";

const db = new Kysely<CmsDatabase>({ dialect: new BunSqliteDialect({ database: new Database(process.env.DB ?? "example/site.sqlite") }) });
await migrateCms(db as Kysely<unknown>, { dialect: "sqlite" });

const cms = createCms({ db, blocks: implementBlocks(cmsBlocks, cmsBlockImpls), canPreview: () => true });
await seedFixtures(cms, demoFixture);

const admin = createAdmin({
	prefix: "/admin",
	brand: "tbtop cms",
	pages: cms.admin.pages,
	navigationGroups: cms.admin.navigationGroups,
	driver: kyselyDriver(db),
	hostRules: kyselyRules(db),
	user: () => ({ id: 1, name: "Demo" }),
	context: cms.admin.context,
});

const esc = (text: unknown) => String(text ?? "").replace(/[&<>"]/g, (c) => `&#${c.charCodeAt(0)};`);

function html({ seo, page, render, breadcrumbs, cms: shared }: PageProps): string {
	const blocks = (render.elements.$root?.children ?? []).map((id) => render.elements[id]);
	return `<!doctype html><html lang="${esc(shared.locale)}"><head><meta charset="utf-8">
<title>${esc(seo.title)}</title><meta name="description" content="${esc(seo.description)}">
<link rel="canonical" href="${esc(seo.canonical)}">
${seo.alternates.map((a) => `<link rel="alternate" hreflang="${esc(a.locale)}" href="${esc(a.url)}">`).join("")}
${seo.jsonLd.map((node) => `<script type="application/ld+json">${JSON.stringify(node).replaceAll("<", "\\u003c")}</script>`).join("")}
</head><body>
<nav>${breadcrumbs.map((c) => `<a href="${esc(c.url)}">${esc(c.label)}</a>`).join(" / ")}</nav>
<h1>${esc(page.heading ?? page.title ?? page.name)}</h1>${page.body}
${blocks.map((block) => `<section data-kind="${esc(block?.type)}"><pre>${esc(JSON.stringify(block?.props, null, 2))}</pre></section>`).join("")}
</body></html>`;
}

const port = Number(process.env.PORT ?? 3100);
Bun.serve({
	port,
	async fetch(request) {
		const handled = (await cms.handle(request)) ?? (await admin.handle(request));
		if (handled !== null) {
			return handled;
		}
		const result = await cms.loadPage(request);
		if (result.type === "redirect") {
			return new Response(null, { status: result.status, headers: { location: result.location } });
		}
		if (result.type === "notFound") {
			return new Response("Not found", { status: 404 });
		}
		return new Response(html(result.props), { headers: { "content-type": "text/html; charset=utf-8", ...result.headers } });
	},
});
console.log(`site on http://localhost:${port}, admin on http://localhost:${port}/admin/pages`);
