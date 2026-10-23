# @tbtop/cms (Node) — 0.0

The tbtop CMS core for Bun, Node and Cloudflare Workers: pages, blocks and SEO
over the host's Kysely, edited in [`@tbtop/admin`](https://github.com/DiVotek/tbtop/tree/main/packages/node).
It reads and writes the same `tbtop_*` tables as the PHP package, so a Node
host can open a database the Laravel package migrated.

The frontend is headless: the host framework (TanStack Start, Hono + React,
Astro…) asks the CMS for a page's props and renders them. `@tbtop/cms/react`
has the React half: the SEO head, the block renderer and the built-in blocks.

## Install (until it is published)

```bash
cd packages/node
./scripts/fetch-admin.sh   # builds @tbtop/admin from DiVotek/tbtop at ADMIN_SHA
bun install
bun run test && bun run typecheck   # `bun test` alone also runs the workbench specs
bun example/server.ts      # demo site on :3100, admin JSON under /admin
```

`TBTOP_TEST_DB=postgres` or `mysql` runs the suite on a server instead of
in-memory SQLite (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`;
defaults postgres/postgres and root/root on 127.0.0.1). Every test gets its
own database, `tbtop_test_*`, which is left behind: drop them for a clean
server. On MySQL, `TIMESTAMP` columns end in 2038, as in the PHP package.

## Workbench (TanStack Start)

`workbench/` is a TanStack Start host with the package installed from a
tarball, the way an app would install it. It renders pages on the server
(SEO head and JSON-LD in the HTML), serves the admin under `/admin` and runs
the Playwright E2E suite.

```bash
cd packages/node/workbench
bun run setup                  # pack @tbtop/cms + @tbtop/admin, then install (needs the install above)
bun run dev                    # http://localhost:3200, admin at /admin/pages
bun run e2e                    # production build + vite preview + Playwright
```

Rerun `bun run setup` after a change to the package. Locally, point
Playwright at an installed Chromium with `PW_CHROMIUM=/path/to/chrome`.

The workbench is a demo, not a host to copy: it signs every visitor in as an
admin and opens drafts and scheduled pages to anyone who adds `?preview`.
The package has no access control of its own yet: the admin `user()`
callback decides who edits and `canPreview` decides who sees unpublished
pages, so a real host must check its own users in both.

## Host API

```ts
import { cmsBlockImpls, cmsBlocks, createCms, defineBlocks, implementBlocks, migrateCms } from "@tbtop/cms";

await migrateCms(db, { dialect: "sqlite" }); // fresh database only; or merge cmsMigrations(...) into your Migrator

// Shared with the client bundle (@tbtop/cms/catalog has only zod):
export const catalog = defineBlocks({ ...cmsBlocks, Faq: { props: faqProps, placements: ["pages", "layout"] } });

const cms = createCms({
	db, // Kysely<CmsDatabase>
	blocks: implementBlocks(catalog, { ...cmsBlockImpls, Faq: { form: (s) => [...], resolve, toSchema } }),
	pageTypes: [], // extra page types next to page, category, article
	url: "https://example.com", // default: the request origin; set it behind a TLS-terminating proxy
	media: (ref) => ({ url: `/storage/${ref}` }),
	canPreview: (request) => isEditor(request),
});

// Frontend
const result = await cms.loadPage(request);
// { type: "page", status, headers, props: { page, render, seo, breadcrumbs, path, isPreview, cms } }
// | { type: "redirect", status, location } | { type: "notFound", status: 404, locale, path, cms }
(await cms.handle(request)) ?? ...; // robots.txt, sitemap.xml, sitemap-N.xml, POST /_cms/forms/...

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

`migrateCms` creates the tables from scratch: run it on a fresh database, not
on one the Laravel package already migrated (its tables exist and the
migration fails). A database Laravel migrated is read as it is, without
`migrateCms`.

`PageProps` is typed as JSON (`Jsonify`), so it goes through a framework
loader as it is: in TanStack Start, return it from a `createServerFn` handler.
A server function answers on its own URL during client navigation, so build
the page request from the route path (see `workbench/src/server/page.ts`).

`seedFixtures(cms, demoFixture)` seeds a declarative fixture through the
package's own write paths (slugs, shared references). It runs once: a
database with pages is left alone.

## React (`@tbtop/cms/react`)

Peers: `react` 19, `@json-render/core` and `@json-render/react` 0.19. The
entry has no server code, so it goes into the browser bundle.

```tsx
import { defineRegistry, JSONUIProvider } from "@json-render/react";
import { cmsBlocks } from "@tbtop/cms/catalog";
import { CmsHead, CmsRenderer, cmsCatalog, cmsComponents, seoHead } from "@tbtop/cms/react";

export const catalog = cmsCatalog({ ...cmsBlocks, Faq: faqMeta });
export const { registry } = defineRegistry(catalog, { components: { ...cmsComponents, Faq } });

// TanStack Start: head: ({ loaderData }) => seoHead(loaderData.seo)
// Anything else on React 19: <CmsHead seo={props.seo} /> (title, meta and links hoist into <head>)
<JSONUIProvider registry={registry}>
	<CmsRenderer spec={props.render} catalog={catalog} registry={registry} showErrors={props.isPreview} />
</JSONUIProvider>;
```

- `seoTags(seo)` is the framework-neutral list behind both; JSON-LD comes out
  escaped (`<`, `>`, `&` as `\u00XX`), so a title cannot close the script.
- `CmsRenderer` drops a block with no component or with props that fail the
  catalog, and one that throws while rendering; with `showErrors` it says so
  in the console. Wrap all of a page's specs (header, body, footer) in one
  `JSONUIProvider`.
- `Hero` and `ContactForm` are unstyled (`cms-hero`, `cms-contact-form*`).
  `useCmsForm(action)` is the ContactForm's submit logic for a custom form
  block: JSON post, field errors, a plain form post without JS.

## Forms and notifications

A form block declares `submit` in its implementation: the validation rules
(Laravel rule strings, run by the admin validator) and an optional `handle`
for the host's own work (a CRM call). `ContactForm` ships with rules and no
handler. The block's props carry `action` and `honeypotField`; post the
fields there as JSON (`{ ok }`, 422 `{ errors }`, 500 `{ errors: { form } }`)
or as a plain form (303 back to the page with `cms_form_status`).

```ts
createCms({
	// ...
	forms: {
		throttle: { max: 10, perSeconds: 60 }, // the default; false turns it off
		clientIp: (request) => server.requestIP(request)?.address, // what the throttle counts by
		allow: (request) => rateLimit(request), // the host's own limiter; false answers 429
		delivery: "inline", // or "outbox": run cms.tasks.deliver() from a cron
	},
	notifications: {
		mail: { send: async (message) => smtp.send(message) }, // { to, from, subject, html, text }
		telegram: { botToken: env.TELEGRAM_BOT_TOKEN },
		bell: { store: kyselyNotifications(db), staff: () => staffUsers() },
	},
});
```

Form posts are rate limited per client, 10 a minute by default (PHP's
`throttle:10,1`): over it the post answers 429 with `Retry-After`. The count
lives in memory, so it is per process, and on Cloudflare Workers per isolate,
which an attacker spread over many isolates gets around; for one count
across instances pass the host's own limiter (KV, Durable Object, Redis) as
`allow`. Given alone, `allow` replaces the built-in throttle; with an
explicit `throttle` both run. A Fetch `Request` has no socket address, so
without `clientIp` the client is the last `X-Forwarded-For` entry (the one
the nearest proxy appended; Cloudflare appends too), else `CF-Connecting-IP`,
else `X-Real-IP`. A host with no proxy in front must pass `clientIp`, or a
client picks its own key; behind two proxies (a CDN, then a load balancer)
the last entry is the CDN's, so pass `clientIp` there too.

Every submission is stored (`forms.store: false` turns that off), then
notified on the channels the merchant enabled in Settings → Notifications
(the bell by default). A failed delivery keeps its error on the row and can
be retried from the inbox; `cms.tasks.deliver()` also picks up rows a dead
worker left in pending or processing for 15 minutes. Inline delivery runs before
the visitor gets the answer; on Cloudflare Workers prefer the outbox and call
`cms.tasks.deliver()` from `scheduled()`. Outside a request, links in notifications use `url` from the
config, so set it when you deliver from a cron.

Template overrides are Liquid (`{{ leadIdentity }}`, `{% for f in fields %}`),
edited per type, format and locale in the notification settings and stored
in `tbtop_notification_templates`. A row the PHP package wrote holds Blade;
it is ignored with a warning and the built-in template answers.

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
- Forms: the submit endpoint, honeypot, a per-client rate limit, stored submissions with D1-safe
  claims, retries, outbox delivery; notifications over mail, Telegram and the
  admin bell with Liquid overrides and a delivery log.
- React: `@tbtop/cms/react` with the SEO head, the block renderer and the
  built-in blocks.
- Admin: pages list and editor (General, SEO, a tab per block, Publishing),
  redirects, layout, shared blocks, languages, site, SEO and notification
  settings, the inbox (list, submission page with status, retry, comments).

## Not yet

Secret settings (Laravel Crypt), legacy HTML richtext conversion (escaped instead), auto-redirects on slug change,
page revisions, page refs, audit log, 404 log, roles/abilities, caching, source-backed page types, the
remaining admin pages (dashboard, users, health, SEO report), test sends
from the notification settings, owner-only template editing.
