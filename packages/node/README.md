# @tbtop/cms (Node) — 0.0

The tbtop CMS core for Bun, Node and Cloudflare Workers: pages, blocks and SEO
over the host's Kysely, edited in [`@tbtop/admin`](https://github.com/DiVotek/tbtop/tree/main/packages/node).
It reads and writes the same `tbtop_*` tables as the PHP package, so a Node
host can open a database the Laravel package migrated.

The frontend is headless: the host framework (TanStack Start, Hono + React,
Astro…) asks the CMS for a page's props and renders them, usually with
json-render's `Renderer` over the block catalog.

## Install (until it is published)

```bash
cd packages/node
./scripts/fetch-admin.sh   # builds @tbtop/admin from DiVotek/tbtop at ADMIN_SHA
bun install
bun test && bun run typecheck
bun example/server.ts      # demo site on :3100, admin JSON under /admin
```

## Workbench (TanStack Start)

`workbench/` is a TanStack Start host with the package installed from a
tarball, the way an app would install it. It renders pages on the server
(SEO head and JSON-LD in the HTML), serves the admin under `/admin` and runs
the Playwright E2E suite.

```bash
cd packages/node/workbench
bun install && bun run setup   # pack @tbtop/cms + @tbtop/admin, reinstall them
bun run dev                    # http://localhost:3200, admin at /admin/pages
bun run e2e                    # production build + vite preview + Playwright
```

Rerun `bun run setup` after a change to the package. Drafts preview with
`?preview`. Locally, point Playwright at an installed Chromium with
`PW_CHROMIUM=/path/to/chrome`.

## Host API

```ts
import { cmsBlockImpls, cmsBlocks, createCms, defineBlocks, implementBlocks, migrateCms } from "@tbtop/cms";

await migrateCms(db, { dialect: "sqlite" }); // or merge cmsMigrations(...) into your Migrator

// Shared with the client bundle (@tbtop/cms/catalog has only zod):
export const catalog = defineBlocks({ ...cmsBlocks, Faq: { props: faqProps, placements: ["pages", "layout"] } });

const cms = createCms({
	db, // Kysely<CmsDatabase>
	blocks: implementBlocks(catalog, { ...cmsBlockImpls, Faq: { form: (s) => [...], resolve, toSchema } }),
	pageTypes: [], // extra page types next to page, category, article
	url: "https://example.com", // default: the request origin
	media: (ref) => ({ url: `/storage/${ref}` }),
	canPreview: (request) => isEditor(request),
});

// Frontend
const result = await cms.loadPage(request);
// { type: "page", status, headers, props: { page, render, seo, breadcrumbs, path, isPreview, cms } }
// | { type: "redirect", status, location } | { type: "notFound", status: 404, locale, path, cms }
(await cms.handle(request)) ?? ...; // robots.txt, sitemap.xml, sitemap-N.xml

// Admin
createAdmin({
	pages: [...cms.admin.pages, ...mine],
	navigationGroups: cms.admin.navigationGroups,
	driver: kyselyDriver(db),
	hostRules: kyselyRules(db),
	context: cms.admin.context, // content locales = admin-active languages
	disks: { public: localDisk("storage", "/storage") }, // the CMS upload fields write to "public"
});
```

`PageProps` is typed as JSON (`Jsonify`), so it goes through a framework
loader as it is: in TanStack Start, return it from a `createServerFn` handler.
A server function answers on its own URL during client navigation, so build
the page request from the route path (see `workbench/src/server/page.ts`).

`seedFixtures(cms, demoFixture)` seeds a declarative fixture through the
package's own write paths (slugs, shared references). It runs once: a
database with pages is left alone.

## What 0.0 has

- Schema and one baseline migration equal to the PHP migrations (SQLite,
  Postgres and MySQL column types).
- Languages, settings (`site.*`/`seo.*` dotted keys), pages with per-locale
  slugs and paths (collision suffixes, cascade to children), optimistic
  locking, layouts (header/footer), shared blocks, redirects (chains, cycles,
  query string kept).
- Blocks: `defineBlocks` catalog, `implementBlocks` server half, the built-in
  Hero and ContactForm, the PHP resolver rules (sort, inactive, unknown and
  broken shared rows skipped, locale flattening with fallback, FormResolver by
  field kind), resolved props validated against the catalog's zod schema.
- Frontend: locale routing (default prefix 301, other locales prefixed),
  live/schedule scope, preview, page props and the shared `cms` prop.
- SEO: the full `seo` prop (title/description templates, Open Graph,
  alternates, x-default, verification tokens), JSON-LD (WebSite,
  BreadcrumbList, the WebPage/Article families, CollectionPage, block nodes,
  site identity), robots.txt, chunked sitemap with hreflang alternates.
- Admin: pages list and editor (General, SEO, a tab per block, Publishing),
  redirects, layout, shared blocks, languages, site and SEO settings.

## Not yet

Form submissions and notifications (the ContactForm action URL is bound but
`/_cms/forms/...` has no handler), secret settings (Laravel Crypt), legacy
HTML richtext conversion (escaped instead), auto-redirects on slug change,
page revisions, page refs, audit log, 404 log, roles/abilities, caching, the
React client entry (`@tbtop/cms/react`: the workbench keeps its json-render
registry and SEO head mapping locally for now), source-backed page types, the
remaining admin pages (dashboard, users, inbox, health, SEO report).
