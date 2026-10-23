# @tbtop/cms (Node) — 0.0

The tbtop CMS core for Bun, Node and Cloudflare Workers: pages, blocks and SEO
over the host's Kysely, edited in [`@tbtop/admin`](https://github.com/DiVotek/tbtop/tree/main/packages/node).
It is a standalone package for new projects: it owns its `tbtop_*` schema
and does not share a database with the Laravel package or migrate one.

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
E2E serves the build with `NODE_ENV=production` (a block whose props fail
the catalog is dropped, not thrown) and `CMS_FORMS_THROTTLE=off` (every spec
posts from one client). The workbench registers the link field's client
from `@tbtop/cms/admin-client` and has its own `Promo` block with a
`linkField` CTA.

The admin signs in through Better Auth (`@tbtop/cms/better-auth`, below):
`admin@admin.com` and `editor@editor.com`, password `password`, are seeded.
E2E signs in as the admin once (the `setup` project) and every spec reuses
that session. The secrets have fixed defaults (`BETTER_AUTH_SECRET`,
`CMS_PREVIEW_SECRET` override them), so the workbench is a demo, not a
deployment. The first request migrates, so Better Auth's start-up check logs
its tables as missing once.

## Host API

```ts
import { cmsBlockImpls, cmsBlocks, createCms, defineBlocks, implementBlocks, migrateCms } from "@tbtop/cms";

await migrateCms(db, { dialect: "sqlite" }); // fresh database only; or merge cmsMigrations(...) into your Migrator

// Shared with the client bundle (@tbtop/cms/catalog has only zod):
export const catalog = defineBlocks({ ...cmsBlocks, Faq: { props: faqProps, placements: ["pages", "layout"] } });
// placements: "pages" (every page type, the default), "layout", or page type / subtype keys
// ("article", "news"): the page editor's Add block offers a kind only where it fits.

const cms = createCms({
	db, // Kysely<CmsDatabase>
	blocks: implementBlocks(catalog, { ...cmsBlockImpls, Faq: { form: (s) => [...], resolve, toSchema } }),
	pageTypes: [recipeType], // extra page types next to page, category, article (see Page types)
	url: "https://example.com", // default: the request origin; set it behind a TLS-terminating proxy
	media: (ref) => ({ url: `/storage/${ref}` }),
	canPreview: (request) => isEditor(request),
	preview: { secret: env.PREVIEW_SECRET, ttlHours: 72 }, // signed preview links for pages that are not live
	authorize: (user, ability) => can(user, ability), // "tbtop-cms.pages", … (CMS_ABILITIES); default: every section open
	redirects: { allowedExternalHosts: ["partner.example"] }, // external redirect targets; default none
	revisions: { keep: 25 }, // content snapshots per page; 0 turns revisions off
	audit: { retentionDays: 365 }, // the admin audit trail's daily prune
	cache: { store: memoryCache(), ttl: 3600 }, // or kvCache(env.KV) on Workers; settings and languages for the frontend
	secretSettings: ["site.api_token"], settingsKey: env.CMS_SETTINGS_KEY, // sealed settings, never sent to the frontend
	hostRules: { ...kyselyRules(db), ...myRules }, // unique/exists and the host's own: for form submits and cms.admin.hostRules
});

// Frontend
const result = await cms.loadPage(request);
// { type: "page", status, headers, props: { page, render, seo, breadcrumbs, path, isPreview, cms } }
// | { type: "redirect", status, location } | { type: "notFound", status: 404, locale, path, cms, props } (props: ErrorPageProps)
const errorProps = await cms.errorPage(request, 500); // ErrorPageProps for the host's own 5xx handler (see Error pages)
(await cms.handle(request)) ?? ...; // robots.txt, sitemap.xml, sitemap-N.xml, POST /_cms/forms/...

// Admin
createAdmin({
	pages: [...cms.admin.pages, ...mine],
	navigationGroups: cms.admin.navigationGroups,
	driver: kyselyDriver(db),
	hostRules: cms.admin.hostRules, // the config's hostRules + link_shape, page_ref
	context: cms.admin.context, // content locales = admin-active languages
	disks: { public: localDisk("storage", "/storage") }, // the CMS upload fields write to "public"
});
```

### Page types

A page type's custom fields are a zod object (`fields`) next to their admin
form (`form`); `definePageType` keeps the literal key, and `createCms` infers
`PageProps` from `pageTypes`, a union by `page.type`:

```ts
import { definePageType, pagesField } from "@tbtop/cms";

export const recipeType = definePageType({
	key: "recipe",
	fields: z.object({ minutes: z.number().nullable(), related: z.array(z.object({ id: z.string(), name: z.string(), url: z.string() })) }),
	form: (s) => [s.number("minutes").label("Minutes"), pagesField("related").of("article")],
});

const cms = createCms({ db, blocks, pageTypes: [recipeType] });
const result = await cms.loadPage(request);
if (result.type === "page" && result.props.page.type === "recipe") {
	result.props.page.fields.minutes; // number | null
}
```

- `createCms` throws when a type's form field names and schema keys differ
  (a schema without a form needs its own `resolveFields`).
- The resolved fields (FormResolver over `form`, or `resolveFields`) are
  parsed with the schema; fields that fail it are left out with a
  `page.fields_resolve_failed` warning, or throw under `strictBlocks`.
- A subtype's fields are optional on its base type's member. A page whose
  type is not registered still renders, outside the union.
- `PageProps<[typeof recipeType]>` names the props type; a bare `PageProps`
  or `Cms` keeps `fields` as untyped JSON.

`migrateCms` creates the tables on a fresh database.
Like `cms:install`, it then gives a database with no live language its
default: `defaultLanguage` (a catalog locale, default `en`; `false` skips it).
A host that merges `cmsMigrations` into its own Migrator calls
`ensureDefaultLanguage(db, "en")` after it.

### Error pages

A 404 from `loadPage` and `cms.errorPage(request, status)` carry
`ErrorPageProps` (ErrorResponse): the live error page of the status class
(`4xx` or `5xx`, the `error_class` column) or, without one, fallback props
with no blocks and the package's heading ("Page not found", "Something went
wrong"). The shape is `PageProps`' (`page`, `render`, `seo`, `breadcrumbs`,
`cms`) plus `status`, `locale` and `path`; `page.id` is null in the fallback.
`seo` is always noindex with no canonical, og:url or JSON-LD (`ErrorSeo`;
`seoTags`/`seoHead`/`CmsHead` take it), and `breadcrumbs` is empty. Render
it with the page component, inside the host's chrome, and answer the status.

```ts
const result = await cms.loadPage(request);
if (result.type === "notFound") return render(<Page {...result.props} />, { status: 404 });
// the host's 500 handler (TanStack errorComponent, Hono onError):
return render(<Page {...(await cms.errorPage(request, 500))} />, { status: 500 });
```

The error pages are system pages an editor edits in the pages list; like
`cms:install`, `ensureErrorPages(cms)` creates the missing ones (published,
in the default language) and a fixture page with `errorClass: "4xx"` is one.
They have no slug and are not in the sitemap. For a 5xx `errorPage` never
throws: when the database is what failed, it logs and answers fallback
props built without it (no settings, languages or layout).

### Access

`authorize(user, ability)` gets the panel user and a section ability, by
the PHP names: `tbtop-cms.pages` (pages and the page editor),
`tbtop-cms.submissions` (inbox), `tbtop-cms.redirects`, `tbtop-cms.design`
(layout and shared blocks; also Share and adding a shared block in the page
editor), `tbtop-cms.languages` and `tbtop-cms.settings` (site, SEO,
notifications). A false hides the section from the nav and answers 403 to
its pages and to every form, action, table and upload on them. Without
`authorize`, every section is open to any panel user, as before.
`tbtop-cms.users` gates the Users page an auth adapter adds.

### Users and sign-in (`@tbtop/cms/better-auth`)

The core asks the host for a panel user (createAdmin's `session` or `user`)
and `authorize`. `@tbtop/cms/better-auth` answers both from a Better Auth
instance with email and password and the admin plugin (`better-auth` is an
optional peer dependency):

```ts
import { cmsAccess, cmsAuth, cmsRoles } from "@tbtop/cms/better-auth";
import { betterAuth } from "better-auth";
import { admin } from "better-auth/plugins";

export const auth = betterAuth({
	database: { db, type: "sqlite" }, // the CMS's Kysely instance
	emailAndPassword: { enabled: true },
	user: { modelName: "tbtop_users" }, // and session, account, verification
	plugins: [admin({ ac: cmsAccess, roles: cmsRoles })],
});
const panelAuth = cmsAuth(auth);
const cms = createCms({ db, blocks, authorize: panelAuth.authorize });
await migrateCms(db, { dialect: "sqlite", userKey: "string" }); // Better Auth's ids are strings
const admin = createAdmin({
	prefix: "/admin",
	pages: [...cms.admin.pages, ...panelAuth.pages(cms)],
	session: panelAuth.session,
	loginPath: panelAuth.loginPath(cms),
	// driver, context, …
});
```

- `session` reads Better Auth's session cookie once per request; the panel's
  logout signs out in Better Auth. Flashed errors and effects stay in a
  cookie sealed with Better Auth's secret (`flash` swaps the store).
- Roles: `cmsStatements` adds a `cms` statement (pages, submissions,
  redirects, settings, design, languages) to the admin plugin's `user` and
  `session` ones. A section ability needs `cms:<section>`; the Users page
  needs `user:list`, and its writes go through the admin plugin's API, so
  Better Auth checks them again. `cmsRoles` has `admin` (everything),
  `editor` (pages, submissions, redirects, design) and `user` (no panel);
  `cmsAccess.newRole({ ... })` makes a host's own, passed to both the plugin
  and `cmsAuth(auth, { roles })`. A user whose roles grant no section and no
  `user:list` is a guest: sign-in refuses them and closes the session.
- Pages: `login` (public), and `users`, `users/new`, `users/{id}/edit`
  (Settings → Users): name, email, role, banned; add with a password, edit
  name and role (not one's own), set a new password, ban (signs out
  everywhere), unban, delete. Better Auth refuses banning or deleting
  oneself.
- Better Auth's tables live in the CMS's database; run its migrations
  (`getMigrations(options).runMigrations()` or its CLI) next to `migrateCms`.
  Mount `auth.handler` (e.g. `/api/auth/*`) only for the host's own
  client-side sign-in or OAuth: the panel does not need it.
- Workers: the build needs `node:crypto` (`nodejs_compat`). Sign-in hashes
  with scrypt, about 75–90 ms of CPU, over the Free plan's 10 ms per
  request; on Workers Paid it fits. Session checks are one database read.

With `preview.secret`, the page editor of a page that is not live offers
a "Preview" link per site locale: an HMAC-signed URL (Web Crypto, so it
runs on Workers) with `expires` and `signature`, valid for `ttlHours`
(default 72). `loadPage` previews a request whose signature matches its
path and has not expired; `canPreview` still grants preview on its own.
`previewLink(cms.site(), path, locale)` builds one for a host's own UI.

The keys in `secretSettings` are sealed with `settingsKey` (AES-256-GCM over
Web Crypto, so Workers too): `settings.set` writes `enc:v1:…`, `settings.get`
opens it, and `cms.settings` never carries them. Without `settingsKey` a
secret cannot be saved; a value sealed with another key, or stored in plain
text, reads as the fallback with a `settings.secret_unreadable` warning.
Changing the key loses the stored secrets. The mail transport and the
Telegram bot token are host config, not settings.

`PageProps` is typed as JSON (`Jsonify`), so it goes through a framework
loader as it is: in TanStack Start, return it from a `createServerFn` handler.
A server function answers on its own URL during client navigation, so build
the page request from the route path (see `workbench/src/server/page.ts`).

`seedFixtures(cms, demoFixture)` seeds a declarative fixture through the
package's own write paths (slugs, shared references). It runs once: a
database with pages is left alone. A fixture language marked `default` takes over
from the installed one (which stays, non-default: pass `defaultLanguage` to
`migrateCms` to install the right one).

## Link and pages fields

`linkField` and `pagesField` go into a host block's `form()` (or a page
type's), as `$s->link()` / `$s->pages()` do in PHP:

```ts
import { linkField, pagesField } from "@tbtop/cms";

Promo: {
	form: (s) => [
		s.text("title").label("Title").translatable(),
		linkField("cta").label("Button").types(["page", "external"]), // default: page, external, email, phone
		pagesField("related").label("Related").of("article").max(3), // of(type, subtype?); exceptPage(id)
	],
},
```

- A link stores `{ type, page, value, anchor, label }` (never an href, so a
  page link survives a slug change) and resolves to `{ href, type, label }`
  or null: a page that is not live or has no slug in the locale, a malformed
  value. The label is per locale; without one it is the page's name or the
  typed target.
- A pages field stores page ids as strings in the order picked and resolves
  to the live ones as `{ id, name, url }[]`; drafts, trashed and unknown ids
  drop out.
- On save the admin checks `link_shape` (type, target format, an existing
  page, label locales and length) and `page_ref` (each id exists) as host
  rules: pass `cms.admin.hostRules` to `createAdmin({ hostRules })`, or a
  save with these fields fails on an unknown rule.
- The pages field is a plain async multi-select on the wire, so the stock
  admin client renders it. The link field is the custom `link` kind: the
  admin bundle imports its client from `@tbtop/cms/admin-client` (below),
  or the client shows its unknown-field placeholder. Its page picker posts to
  `{adminPrefix}/link/page-search/select-options/page`, an admin page the
  CMS adds to `cms.admin.pages`.

```tsx
// The admin bundle's entry. Peers: react 19 and @tbtop/inertia-admin (optional for the other entries).
import { linkFieldClient } from "@tbtop/cms/admin-client"; // defineFieldClient("link", …): the import registers it
void linkFieldClient;
```

The Node page search answers each page with one label, its name in the
default language, where PHP's sends per-locale names: the link text's
placeholder shows that label in every locale.

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
- Google Tag Manager (SEO settings → Integrations, `seo.gtmContainerId`,
  a `GTM-…` id or null): `seoTags`/`seoHead`/`CmsHead` put Google's loader
  script first; render `<GtmNoscript id={props.seo.gtmContainerId} />` right
  after `<body>` (`gtmScript`/`gtmNoscriptHtml` give both halves as strings).
  An inline script only runs from server-rendered HTML, so the tags load on
  full page loads; count SPA page views with a History Change trigger.
- `CmsRenderer` drops a block with no component or with props that fail the
  catalog, and one that throws while rendering; with `showErrors` it says so
  in the console. Wrap all of a page's specs (header, body, footer) in one
  `JSONUIProvider`.
- `Hero` and `ContactForm` are unstyled (`cms-hero`, `cms-contact-form*`).
  `useCmsForm(action)` is the ContactForm's submit logic for a custom form
  block: JSON post, field errors, a plain form post without JS.
- The Hero's CTA link is read the way a browser reads it (leading controls
  and spaces dropped, tabs and newlines removed): a relative link (`/contact`,
  `blog`, `#form`, `?ref=x`) or an http(s), `mailto:`, `tel:` or messenger
  (`tg:`, `viber:`, `whatsapp:`, `skype:`, `sms:`) link passes, any other
  scheme (`javascript:`, `data:`) does not. The editor refuses it, and a
  stored one resolves to no CTA, so the component renders only a safe href.

## Forms and notifications

A form block declares `submit` in its implementation: the validation rules
(Laravel rule strings, run by the admin validator) and an optional `handle`
for the host's own work (a CRM call). `ContactForm` ships with rules and no
handler. The block's props carry `action` and `honeypotField`; post the
fields there as JSON (`{ ok }`, 422 `{ errors }`, 500 `{ errors: { form } }`)
or as a plain form (303 back to the page with `cms_form_status`).

The rules run with the admin's rule set: the built-in Laravel rules, the
CMS's `link_shape` and `page_ref`, and the config's `hostRules` (pass
`kyselyRules(db)` there for `unique`/`exists`, and a rule of the host's
own, `{ check, message }`, for a block that needs one). The same set is
`cms.admin.hostRules` for createAdmin. A rule nobody defines, or a `rules`
that throws, answers `failed` (500 `{ errors: { form } }`, logged as
`form.submit.validation_failed`) and stores nothing.

A form an editor does not place (one a page type's `template()` or the host
renders) gets a name: `formName: "service-order"` next to `submit` in its
implementation (PHP's `HasForm`). It then posts from any live page, and
`await cms.site(request).formProps("service-order", page, locale)` gives the
host its `{ action, honeypotField }` (Forms::props; the default locale
unless given). A post by name runs `submit` with empty block data. A name
needs `submit` and is unique; an unknown name throws in `formProps`.

```ts
createCms({
	// ...
	forms: {
		throttle: { max: 10, perSeconds: 60 }, // the default; false turns it off
		clientIp: (request) => server.requestIP(request)?.address, // what the throttle counts by
		allow: (request) => rateLimit(request), // the host's own limiter; false answers 429
		delivery: "inline", // or "outbox": run cms.tasks.deliver() from a cron
		onSubmission: ({ submission, payload, page, blockId, locale }) => crm.lead(payload), // SubmissionReceived
	},
	notifications: {
		mail: { send: async (message) => smtp.send(message) }, // { to, from, subject, html, text }
		telegram: { botToken: env.TELEGRAM_BOT_TOKEN },
		bell: { store: kyselyNotifications(db), staff: () => staffUsers() },
		onDelivery: (event) => metrics.count(event.channel, event.status), // NotificationSent / NotificationFailed
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

`forms.onSubmission` runs after a valid submission is stored (`submission`
is null with `store: false`) and before the block's own `handle`, as PHP's
`SubmissionReceived` listeners do; the honeypot never reaches it.
`notifications.onDelivery` hears each (recipient, channel) pair once it is
sent or has failed (`status`, `stage` render or send, `error`, `to` the
recipient), on retries too. Both are awaited; a throw in either is logged
and never fails the submit or the delivery.

Template overrides are Liquid (`{{ leadIdentity }}`, `{% for f in fields %}`),
edited per type, format and locale in the notification settings and stored
in `tbtop_notification_templates`.

## Deploy

On a VPS (Node 20+ or Bun), run `tbtop-cms migrate` on each release and
`tbtop-cms scheduled` from cron every minute; both read the default export
of `cms.config.{ts,mts,js,mjs}` (or `--config`). The build only needs
`node:async_hooks` (from `@tbtop/admin`'s request context) outside the CLI.

On Cloudflare Workers, use a D1 Kysely dialect (D1 is SQLite: migrate with
`dialect: "sqlite"`), set `compatibility_flags = ["nodejs_als"]` (or
`nodejs_compat`) for `node:async_hooks`, add a `* * * * *` cron trigger
whose `scheduled()` calls `cms.tasks.scheduled()`, and pass
`{ waitUntil: ctx.waitUntil }` to `loadPage`. The CLI does not reach D1:
run `migrateCms` from the Worker (a deploy hook or a guarded route).

## What 0.0 has

- Schema and one baseline migration equal to the PHP migrations (SQLite,
  Postgres and MySQL column types).
- Languages, settings (`site.*`/`seo.*` dotted keys), pages with per-locale
  slugs and paths (collision suffixes, cascade to children), optimistic
  locking, layouts (header/footer), shared blocks, redirects (chains, cycles,
  query string kept; a moved page path leaves a 301 auto rule, repointed on the
  next move and dropped when a page takes the path again).
- Revisions: a snapshot of the editorial fields on create, on each save that
  changes one and on restore, newest `revisions.keep` kept;
  `pages.restore(id, revisionId)` and the editor's Revisions slide-over put
  the content back without touching status, tree or slugs.
- Page references: a page type's `pagesField` ids are indexed in
  `tbtop_page_refs` on every save; `site.referencedBy(page, field, { from })`
  and `site.referencedByLinks(...)` list the live pages that pick a page, and
  a type's `resolveFields` reads them as `ctx.referencedBy(field, from)`.
  `rebuildPageRefs(db, site.pageTypes)` rewrites the index.
- 404 log: `loadPage` counts each miss per locale and path (scanner probes
  in `notFound.ignore` and redirected paths left out); pass
  `{ waitUntil: ctx.waitUntil }` on Workers to write it after the answer.
  `seo.not_found.enabled` and `retention_days` are in the SEO settings,
  `cms.tasks.pruneNotFound()` prunes, and the admin's 404 log turns a miss
  into a redirect or ignores it.
- Cache: with `cache: { store: memoryCache() }` (one server) or
  `kvCache(env.KV)` (Workers), frontend reads of settings and languages go
  through a content-versioned cache (`ttl` default 3600 s). Admin reads stay
  live and every write through the CMS bumps the version; a write made
  around the CMS shows once the entry expires.
- Audit log: a write to a page, redirect, language, shared block or layout
  made by a signed-in admin records the event (created, updated, deleted,
  restored, revision_restored), the user and the changed fields with old
  and new values in `activity_log`; large fields (body, content, fields,
  seo) are named without their values, layout blocks as `{id, kind}`.
  Writes outside the admin (seeders, the frontend, a cron) record nothing.
  `cms.tasks.audit({ subjectType, subjectId, limit })` reads it,
  `cms.tasks.pruneAudit(days)` prunes.
- Tasks and the CLI: `cms.tasks.scheduled()` is the one scheduler entry
  (outbox delivery; daily, the 404 and audit prune and the SEO snapshot; the heartbeat); call it from a
  cron every minute or from Workers `scheduled()`. `cms.tasks.health()`
  runs the checks (site URL, default language, block rows, page types,
  notifications, scheduler, secrets). The `tbtop-cms` bin runs the same over
  a `cms.config.ts` whose default export is the host's Cms: `migrate`,
  `doctor`, `scheduled`, `deliver`, `not-found:prune`, `page-refs:rebuild`, `seo:snapshot`,
  `audit [--type page] [--id N] [--limit N]`, `audit:prune [--days N]`
  (Bun runs a `.ts` config as is; Node needs a `.js` one or a TS loader).
- Blocks: `defineBlocks` catalog, `implementBlocks` server half, the built-in
  Hero and ContactForm, the PHP resolver rules (sort, inactive, unknown and
  broken shared rows skipped, locale flattening with fallback, FormResolver by
  field kind), resolved props validated against the catalog's zod schema
  (a mismatch is dropped with a warning in production and throws
  `BlockResolutionError` in development: on Node and Bun a `NODE_ENV`
  other than `production`, unset included; never on Workers; `strictBlocks` true or false overrides it).
- Frontend: locale routing (default prefix 301, other locales prefixed),
  live/schedule scope, preview, page props and the shared `cms` prop.
- SEO: the full `seo` prop (title/description templates, Open Graph,
  alternates, x-default, verification tokens, the GTM container id), JSON-LD (WebSite,
  BreadcrumbList, the WebPage/Article families, CollectionPage, block nodes,
  site identity), robots.txt, chunked sitemap with hreflang alternates.
- Forms: the submit endpoint, honeypot, a per-client rate limit, stored submissions with D1-safe
  claims, retries, outbox delivery; notifications over mail, Telegram and the
  admin bell with Liquid overrides and a delivery log.
- React: `@tbtop/cms/react` with the SEO head, the block renderer and the
  built-in blocks.
- Admin: the dashboard at `/{prefix}/dashboard` (page, inbox and layout
  tiles trended over 14 days, leads-by-status and daily submission charts,
  the latest pages and submissions; the inbox parts need
  `tbtop-cms.submissions`), the SEO report (missing titles, descriptions,
  translations and OG images, duplicates, noindex, per live page and
  frontend locale, with a daily history in `tbtop_seo_audit_snapshots`),
  pages list and editor (General, SEO, a tab per block, Publishing),
  redirects, layout, shared blocks, languages (added from the locale
  catalog; trash, restore, delete permanently; the default is not
  deletable), site, SEO and notification
  settings, the inbox (list, submission page with status, retry, comments);
  sign-in and Users through Better Auth (`@tbtop/cms/better-auth`).

## Not yet

Source-backed page types, test sends
from the notification settings, owner-only template editing.
