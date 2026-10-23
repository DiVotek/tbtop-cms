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
The admin `user()` callback decides who gets into the panel, `authorize`
which CMS sections each user sees, and `canPreview` (or a signed preview
link) who sees unpublished pages, so a real host must check its own users
there.

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
	hostRules: { ...kyselyRules(db), ...cms.admin.hostRules }, // link_shape, page_ref
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

`migrateCms` creates the tables from scratch: run it on a fresh database, not
on one the Laravel package already migrated (its tables exist and the
migration fails). A database Laravel migrated is read as it is, without
`migrateCms`.
Like `cms:install`, it then gives a database with no live language its
default: `defaultLanguage` (a catalog locale, default `en`; `false` skips it).
A host that merges `cmsMigrations` into its own Migrator calls
`ensureDefaultLanguage(db, "en")` after it.

### Access

`authorize(user, ability)` gets the panel user and a section ability, by
the PHP names: `tbtop-cms.pages` (pages and the page editor),
`tbtop-cms.submissions` (inbox), `tbtop-cms.redirects`, `tbtop-cms.design`
(layout and shared blocks; also Share and adding a shared block in the page
editor), `tbtop-cms.languages` and `tbtop-cms.settings` (site, SEO,
notifications). A false hides the section from the nav and answers 403 to
its pages and to every form, action, table and upload on them. Without
`authorize`, every section is open to any panel user, as before.

With `preview.secret`, the page editor of a page that is not live offers
a "Preview" link per site locale: an HMAC-signed URL (Web Crypto, so it
runs on Workers) with `expires` and `signature`, valid for `ttlHours`
(default 72). `loadPage` previews a request whose signature matches its
path and has not expired; `canPreview` still grants preview on its own.
`previewLink(cms.site(), path, locale)` builds one for a host's own UI.

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
  rules: merge `cms.admin.hostRules` into `createAdmin({ hostRules })`, or a
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
- Admin: pages list and editor (General, SEO, a tab per block, Publishing),
  redirects, layout, shared blocks, languages (added from the locale
  catalog; trash, restore, delete permanently; the default is not
  deletable), site, SEO and notification
  settings, the inbox (list, submission page with status, retry, comments).

## Not yet

Secret settings (Laravel Crypt), legacy HTML richtext conversion (escaped instead), auto-redirects on slug change,
page revisions, the page-refs reverse index (`referencedBy`), audit log, 404 log, caching, source-backed page types, the
remaining admin pages (dashboard, users, health, SEO report), test sends
from the notification settings, owner-only template editing.
