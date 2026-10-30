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
bun run test && bun run typecheck
TBTOP_DEMO=1 bun example/server.ts   # demo site on :3100, admin JSON under /admin (everyone is signed in)
```

`TBTOP_TEST_DB=postgres` or `mysql` runs the suite on a server instead of
in-memory SQLite (`DB_HOST`, `DB_PORT`, `DB_USERNAME`, `DB_PASSWORD`;
defaults postgres/postgres and root/root on 127.0.0.1). Every test gets its
own database, `tbtop_test_*`, which is left behind: drop them for a clean
server. On MySQL, `TIMESTAMP` columns end in 2038, as in the PHP package.

The suites run on Bun (`bun:test`, `bun:sqlite`). The package itself runs on
Node 22+ too: the workbench's E2E job serves it from Node (`vite preview`).

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
E2E serves the build with `NODE_ENV=production` (the workbench turns
`strictBlocks` on only outside production, so a block whose props fail the
catalog is dropped, not thrown) and `CMS_FORMS_THROTTLE=off` (every spec
posts from one client). The workbench registers the link field's client
from `@tbtop/cms/admin-client` and has its own `Promo` block with a
`linkField` CTA.

The admin signs in through Better Auth (`@tbtop/cms/better-auth`, below):
`admin@admin.com` and `editor@editor.com`, password `password`, are seeded.
E2E signs in as the admin once (the `setup` project) and every spec reuses
that session. The secrets have fixed defaults (`BETTER_AUTH_SECRET`,
`CMS_PREVIEW_SECRET` override them), so the workbench is a demo, not a
deployment. The first request migrates Better Auth's tables too, so the
workbench turns off Better Auth's schema check (`advanced.database.validateSchema`),
which runs when `betterAuth()` is built and would log them as missing. That is
for the demo only: a deployed host migrates before it serves (a deploy step)
and keeps the check on, so a missing or drifted auth table is reported.

## Quick start: `defineSite` + `serve` / `cloudflare`

The front door. A site is records of fields: one description gives the admin
form, the zod schema and the TypeScript type, so the three cannot drift. The
full example, used as the package's proof test, is [`example/site`](example/site).

```ts
// site.ts: client-safe, the browser imports `site.catalog` from it
import { block, defineSite, f, formBlock, hero, pageType } from "@tbtop/cms/site";

export const site = defineSite({
	blocks: {
		Hero: hero,
		Quote: block({ text: f.textarea().translatable(), by: f.text() }, { label: "Quote" }),
		Callback: formBlock({ heading: f.text() }, { label: "Call me back", submit: { schema: callbackSchema } }),
	},
	pages: {
		page: { tagline: f.text() }, // extends the built-in `page` type
		post: pageType({ author: f.text(), readingTime: f.number() }, { label: "Post", jsonLd: "BlogPosting" }),
	},
	jsonLd: { /* schema types: (ctx, { baseNode, identityId }) => nodes */ },
	seo: async (nodes, ctx, h) => nodes, // every page's JSON-LD, last
	settings: { Company: { phone: f.text() } }, // Site settings tabs
	collections: { review: reviews }, // records with no page: see "Collections"
});

// server.ts (Node / Bun)
import { serve } from "@tbtop/cms/serve";
export default serve(site, { db, render, renderError, admin: { pages: [myPage] }, config: { timezone: "Europe/Kyiv" } });

// worker.ts (Cloudflare Workers: DB, CACHE, MEDIA bindings by name)
import { cloudflare } from "@tbtop/cms/cloudflare";
export default cloudflare(site, { render, renderError });
```

`page.fields.readingTime` is `number | null` in `render` without a hand-written
type. A form block gets `action` and `honeypotField` props: post the form there.

**Block props from `resolve`.** The fields are the editor's input; the props
are what the component gets. Without `resolve` they are the same. With it, the
props are what `resolve` returns, and their type comes from that return:

```ts
Contacts: block({ heading: f.text().translatable() }, {
	// `fields` is the fields resolved and parsed: { heading: string | null }
	resolve: async (fields, ctx) => ({ heading: fields.heading ?? "", phone: (await settings.of(ctx)).phone ?? "" }),
}),

// the component, in the browser
import type { BlockProps } from "@tbtop/cms/site";
function Contacts(props: BlockProps<typeof site, "Contacts">) { /* { heading: string; phone: string } */ }
```

No zod schema follows from a function, so the catalog checks no shape for a
block with `resolve` (any object passes): its return type is the check.

**Settings.** The Site settings reach code as one object in the request's
locale: the built-in `name`, `logo` and `favicon` (URLs), the published
`phones` and `emails` (strings) and `socials` (`{label, name, href}`) from the
built-in collections, then every field of the site's tabs by name (those
six names are taken), resolved as a block field is (a translatable field
to its value in the locale, an image to its URL, a link to its target). Secret
keys are not in it. It is `ctx.settings()` in a resolve or a JSON-LD
callback, `props.cms.settings` on the page and `cms.settings(locale)` on a
server route; its rows come through the content cache, once per request.
Declared apart with `defineSettings`, the tabs type it:

```ts
export const settings = defineSettings({ Company: { phone: f.text(), address: f.textarea().translatable() } });
export const site = defineSite({ settings, blocks: { /* resolve: (await settings.of(ctx)).phone */ } });

type Props = PageProps<typeof site.pageTypes, typeof site.settings>; // props.cms.settings.address: string | null
```

On `createCms`, `siteSettings.schema` (zod over the host keys) parses the
object, and `PageProps<T, BuiltinSettings & z.output<typeof schema>>` types it.

**Fields (`f.*`).** `text`, `textarea`, `richtext`, `number`, `boolean`, `date`,
`select(options)` (one value or null), `select(options, { multiple: true })`
(an array), `tags`, `image`, `link(types?)`, `pages(type?)`,
`collection(target, { single?, max?, modes? })`, `repeater(fields)`.
Each takes `.label()`, `.helperText()`, `.translatable()`, `.required()`.
Anything else of the admin: `f.custom((s, name) => s.colorpicker(name), z.string().nullable())`.

**What `serve` does.** Better Auth from `db` (dialect read from the Kysely
adapter, `tbtop_*` tables, admin plugin, email and password with public
sign-up off), the admin panel, one route handler (admin, auth, robots,
sitemaps, form posts), then pages through `render`. On Node the first request (or
awaiting `ready()` of `serve`'s result in a deploy step) migrates the CMS and Better Auth, seeds
`seed` into an empty site, creates the 404/5xx pages and the first admin.
`cloudflare` does the same without migrating: on Workers the tables come on
deploy from SQL files (see "Migrations on Workers").
`handle(request)` is the same without pages, for a framework router.

**Secrets.** `BETTER_AUTH_SECRET` is required (no default secret: it throws).
The first admin comes only from `TBTOP_ADMIN_EMAIL` / `TBTOP_ADMIN_PASSWORD`.
The boot creates that admin only while the site has no admin user at all:
while one exists, changing the secrets changes nothing, so rotate the password
on the Users page. With the last admin removed, the next boot creates one from
the secrets again: unset `TBTOP_ADMIN_PASSWORD` after the first boot unless
you want that recovery path. With its own auth (`auth: false` +
`config.auth`) a host whose user ids are strings passes `userKey: "string"`.
On `createCms` with `defaultAuth`, pass the same secrets as
`defaultAuth(db, { firstAdmin: { email, password } })`: `migrate()` creates
the admin by the same rule, and `setup()` alone does it where the tables come
from elsewhere (D1 migration files).
`users.ensure(email, role, { password })` seeds other users in a seed or a
test; it refuses the `admin` role and a role the site does not define. On Workers these are secrets and vars of
the Worker (`wrangler secret put`); `BETTER_AUTH_URL` and `MEDIA_URL` are optional.

**Workers bindings.** `DB` (D1), `CACHE` (KV, optional), `MEDIA` (R2,
optional; uploads stay in memory without it). `scheduled` runs the CMS's
tasks: add a cron trigger. See [`example/site/wrangler.jsonc`](example/site/wrangler.jsonc).
The seed, the error pages and the first admin run on each isolate's first
request. Two cold isolates on an empty site may both try: unique keys
(language locale, page slug, error class, user email) stop the second, whose
request fails once, but it can leave a page row without a slug. With a `seed`,
open the site once after the first deploy, before it takes traffic.

**Migrations on Workers.** D1 has no transactions and an isolate's first
request may come to several isolates at once, so a Worker never migrates
itself. The CLI writes the SQL and wrangler applies it on deploy:

```bash
bunx tbtop-cms d1:migrations --entry worker.ts   # adds missing files to ./migrations
wrangler d1 migrations apply DB --remote         # in the deploy, before wrangler deploy
```

`d1:migrations` writes one file per CMS migration (with the default
language, `--locale uk` or `--no-language`) and one with Better Auth's
tables, taken from the options `cloudflare(site, options)` was given
(`--entry`; without it, the defaults). Files already in the folder are never
rewritten: rerun it after a package update or a new Better Auth plugin and it
adds only what is missing (a new plugin's columns go in `better_auth_2`).
Commit the folder. `--out` sets wrangler's `migrations_dir` (default
`migrations`). A `.ts` entry needs a runtime that imports TypeScript: `bunx`,
or Node with a loader (`node --import tsx`). No D1 is reached: the SQL is compiled, not read from a database.
A Worker whose files were not applied fails its first request with that hint.

**Low level.** Everything below (`createCms`, `implementBlocks`,
`defaultAuth` from `@tbtop/cms/better-auth`, `createCmsAdmin`, `cmsRoutes`, `migrateAll`) stays public:
`siteApp(site, runtime, options)` from `@tbtop/cms/serve` is built from it,
and a host with its own auth (`auth: false` + `config.auth`) or its own
runtime (`runtimeFromEnv`, `d1Kysely`, `workerEntry` from
`@tbtop/cms/cloudflare`) assembles the parts itself.

## Host API

```ts
import { cmsBlockImpls, cmsBlocks, createCms, defineBlocks, implementBlocks, migrateCms } from "@tbtop/cms";

await migrateCms(db, { dialect: "sqlite" }); // fresh database only; or merge cmsMigrations(...) into your Migrator

// Shared with the client bundle (@tbtop/cms/catalog has only zod):
export const catalog = defineBlocks({ ...cmsBlocks, Faq: { props: faqProps, placements: ["pages", "layout"] } });
// placements: "pages" (every page type, the default), "layout", or page type / subtype keys
// ("article", "news"): the page editor's Add block offers a kind only where it fits.
// A subtype goes by its own key ("product", not "article/product"); a key that names
// no registered type is never offered, and createCms warns `block.placement_unknown`.

const cms = createCms({
	db, // Kysely<CmsDatabase>
	blocks: implementBlocks(catalog, { ...cmsBlockImpls, Faq: { form: (s) => [...], resolve, toSchema } }),
	// toSchema: (props, ctx) => ({ ...baseNode(ctx, "Service"), provider: { "@id": identityId(ctx.site) } })
	// baseNode gives the page's name, url, description, image and dates; identityId links to the site identity.
	// Lists of cards go in a repeater; its translatable cells are edited per locale:
	// form: (s) => [s.repeater("items").fields([s.text("q").translatable(), s.text("icon")])]
	// resolve() gets a `.translatable()` field already in the page's locale (default as fallback),
	// so it never reads `{ en, de }` itself. A link is a linkField (see below), not a text field:
	// its target is checked on save and on render, a free-text href is not.
	pageTypes: [recipeType], // extra page types next to page, category, article (see Page types)
	schemaTypes: [productSchema], // extra JSON-LD types a page type's schemaTypes can name (see Page types)
	// The page's JSON-LD, last: add or replace nodes. Keep "@id": identityId(ctx.site) on your own organization node
	// and Article author/publisher link to it.
	seo: { extend: (nodes, ctx) => [...nodes.filter((n) => n["@id"] !== identityId(ctx.site)), myOrganization(ctx)] },
	url: "https://example.com", // default: the request origin; set it behind a TLS-terminating proxy
	timezone: "Europe/Kyiv", // app.timezone: admin dates, dashboard days, SEO {date}; stored dates stay UTC (default UTC)
	media: (ref) => ({ url: `/storage/${ref}` }), // width/height/alt too, for og:image and ctx.mediaImage()
	imageLoader: (ref, width) => `/cdn-cgi/image/width=${width}/storage/${ref}`, // srcset copies for ctx.mediaImage()
	canPreview: (request) => isEditor(request),
	preview: { secret: env.PREVIEW_SECRET, ttlHours: 72 }, // signed preview links for pages that are not live
	auth: cmsAuth(auth), // a betterAuth({...}) instance: section access, the panel session, sign-in and Users pages (see Users and sign-in)
	// authorize: (user, ability) => can(user, ability), // without an auth adapter: "tbtop-cms.pages", … (CMS_ABILITIES)
	adminPrefix: "/admin", // the panel's prefix; robots.txt disallows it
	llmsTxt: { sections: [docsSection] }, // host sections of /llms.txt; enabled: false leaves the URL to the host (see llms.txt)
	// markdown: { enabled: false }, // never serve pages as markdown (see Markdown for agents)
	admin: { locales: ["en", "uk"], locale: "en" }, // the panel's UI languages (not content languages), saved per admin
	redirects: { allowedExternalHosts: ["partner.example"] }, // external redirect targets; default none
	revisions: { keep: 25 }, // content snapshots per page; 0 turns revisions off
	audit: { retentionDays: 365 }, // the admin audit trail's daily prune
	cache: { store: memoryCache(), ttl: 3600 }, // or kvCache(env.KV) on Workers; the frontend's internal reads (see Caching)
	secretSettings: ["site.api_token"], settingsKey: env.CMS_SETTINGS_KEY, // sealed settings, never sent to the frontend
	// The host's own tabs on Site settings: a field "tagline" saves at site.tagline and reaches the settings
	// object as `tagline`; list a token's key (site.<name>) in secretSettings to seal it instead.
	siteSettings: { tabs: (s) => [{ label: "Brand & forms", children: [s.text("tagline").label("Tagline")] }], schema: z.object({ tagline: z.string().nullable() }) },
	hostRules: { ...kyselyRules(db), ...myRules }, // the admin's unique/exists and the host's own rules: cms.admin.hostRules
});

// Frontend
const result = await cms.loadPage(request);
// { type: "page", status, headers, props: { page, render, seo, breadcrumbs, path, isPreview, cms } }
// | { type: "redirect", status, location } | { type: "notFound", status: 404, locale, path, cms, props } (props: ErrorPageProps)
const errorProps = await cms.errorPage(request, 500); // ErrorPageProps for the host's own 5xx handler (see Error pages)
(await cms.handle(request)) ?? ...; // robots.txt, llms.txt, sitemap.xml, sitemap-N.xml, POST /_cms/forms/..., /about.md

// Admin
createAdmin({
	...cms.admin.options({ pages: mine }), // see Admin panel
	disks: { public: localDisk("storage", "/storage") }, // the CMS upload fields write to "public"
	rootView: rootView({ head }),
});
```

### Admin panel

`cms.admin.options()` is createAdmin's config in one spread, read from the
CMS config: the panel's look (`cms.admin.panel`: brand, topbar-sidebar,
compact, nav groups, chrome with bell, inbox and view site, as PHP's
CmsPanel), `prefix` (`adminPrefix`), `pages`, `hostRules` (the config's plus
`link_shape` and `page_ref`), `context` (content locales from the
admin-active languages), the auth adapter's `session`, `loginPath` and
`userMenuItems`,
`notifications.store` (`notifications.bell.store`), `locales` and `locale`
(`admin.locales`, `admin.locale`) and `driver: kyselyDriver(config.db)`. A
key after the spread overrides it.

`admin.locales` are the languages the panel itself speaks, apart from the
content languages pages are written in. With two or more, the user menu
offers them and each admin's pick is saved to `tbtop_user_profiles.locale`
(through the panel's `userLocale`/`onLocaleChange`), so it outlives the
session. Staff notifications read the same locales: the bell each admin's
saved one, mail and Telegram `admin.locale`, never the page's language; the
template overrides in Settings → Notifications are per admin locale.

`pages` is the host's pages, added after the CMS's and the auth adapter's,
or a function that gets those and returns the panel's:

```ts
createAdmin({ ...cms.admin.options({ pages: [reportsPage] }), rootView });
createAdmin({ ...cms.admin.options({ pages: (cms) => cms.filter((page) => page.path !== "audit") }), rootView });
```

The CMS pages' `path`s are public contract, under the prefix: `dashboard`,
`pages`, `pages/$page/edit`, `form-submissions`, `form-submissions/$submission`,
`redirects`, `not-found`, `seo-report`, `layout`, `layout/$slot`,
`shared-blocks`, `shared-blocks/$block/edit`, `blocks`, `languages`,
`languages/new`, `languages/$language/edit`, `settings/site`, `settings/seo`,
`notification-settings`, `site-health`, `audit`, `audit/$activity` and
`link/page-search`; `@tbtop/cms/better-auth` adds `login`, `users`,
`users/new`, `users/$user/edit` and `profile`. CMS pages link to each other (the
dashboard, the bell and inbox, the page editor's shared blocks, the link
field's page picker), so a filtered-out page another one links to leaves a
dead link or a broken field. To hide a section from some users, return false
from `authorize` instead: the nav drops it and its links answer 403.

`cms.admin.pages`, `panel`, `hostRules` and `context` stay for a host that
wires createAdmin itself.

### Page types

A page type's custom fields are a zod object (`schema`) next to their admin
form (`form`); `definePageType` keeps the literal key, and `createCms` infers
`PageProps` from `pageTypes`, a union by `page.type`:

```ts
import { definePageType, pagesField } from "@tbtop/cms";

export const recipeType = definePageType({
	key: "recipe",
	schema: z.object({ minutes: z.number().nullable(), related: z.array(z.object({ id: z.string(), name: z.string(), url: z.string() })) }),
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

A page type offers JSON-LD types by key (`schemaTypes`, the first is the
default); a subtype without its own inherits its base's. A host adds a type
with `createCms({ schemaTypes })`, for example a catalog item:

```ts
const productSchema: SchemaTypeDef = {
	key: "Product",
	build: (ctx) => [{ "@context": "https://schema.org", "@type": "Product", name: ctx.name, url: ctx.url,
		offers: { "@type": "Offer", price: ctx.page.fields?.price, priceCurrency: "UAH" } }],
};
const productType = definePageType({ key: "product", base: "article", schemaTypes: ["Product"], schema, form });
```

A def with a built-in key (`page`, `category`, `article`) extends that type
instead of adding one: its `form`, `schema`, `resolveFields`, `template`,
`label` and `schemaTypes` replace the built-in's (list the built-in schema
types too to keep them pickable), and the tree rules stay the package's
(setting `allowedChildren`, `requiresParent`, `maxDepth` or `base` throws).
`page.fields` is typed from its schema as for any other type.

```ts
const page = definePageType({ key: "page", form: (s) => [s.text("tagline").translatable()], schema: z.object({ tagline: z.string().nullable() }) });
```

`migrateCms` creates the tables on a fresh database.
Like `cms:install`, it then gives a database with no live language its
default: `defaultLanguage` (a catalog locale, default `en`; `false` skips it).
A host that merges `cmsMigrations` into its own Migrator calls
`ensureDefaultLanguage(db, "en")` after it.

### Tags and category archives

A tag is a page: a category subtype the articles pick in a `pagesField`. Its
URL, name per locale, SEO, 301 on a slug change and editor come with it.

```ts
import { definePageType, pagesField } from "@tbtop/cms";

const tag = definePageType({
	key: "tag",
	base: "category",
	listedInAll: false, // off the Pages "All" and "Category" tabs, on a "Tag" tab of its own
	archiveOf: "tags", // noindex and out of the sitemap while nothing live picks it
	form: () => [],
	resolveFields: async (_input, ctx) => ({ articles: await ctx.referencedByPaged("tags", { from: "article", perPage: 20 }) }),
});
const post = definePageType({
	key: "post",
	base: "article",
	form: () => [pagesField("tags").of("category", "tag").creatable().max(10)],
});
```

- Make a "Tags" category and the first tag under it in Pages. After that
  `creatable()` lets the editor type a new tag into the select: it is created
  published, next to the newest tag (`/tags/street-food`). Until a first tag
  exists the select refuses with a validation error, since only then is it
  known where tags live. A name an existing tag already has in the editor's locale (any case) picks
  that tag instead of making a second one.
- `ctx.referencedByPaged(field, { from, perPage })` and
  `ctx.childrenPaged({ from, perPage })` (a category's own articles) give
  `{ items, pagination: { page, pages, total, perPage } }`, newest first by
  `publishedAt`. The page number is the request's `?page=`. From page 2 on the
  canonical and `og:url` carry `?page=N`; a page past the last is a 404.
  `?page=` is one number per URL and belongs to the page's own archive: a
  listing of another page (sidebar siblings) starts at page 1.
  Outside a page render, `site.referencedByLinksPaged(page, field, options)`
  and `site.childrenLinksPaged(page, options)` take the same options plus
  `locale` and `page`.
- With `defineSite`, put the options on `pageType(fields, { base, listedInAll,
  archiveOf })`, pick tags with `f.pages("category", { subtype: "tag",
  creatable: true, max: 10 })`, and read the archive in a block's `resolve`
  through `ctx`.
- Merging tags, `article:tag` meta and an archive that also lists its child
  categories' articles are not there yet.

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

### llms.txt

`cms.handle()` answers `GET /llms.txt` (llmstxt.org, the PHP package's
`LlmsTxt`) once an editor turns it on in SEO settings → *For AI*; it 404s
before that and while indexing is off. Every other frontend language has
its own at `/{locale}/llms.txt` (`/uk/llms.txt`; `/en/llms.txt` for the
default one 301s to `/llms.txt`). The file, in its language:
the site name as `#`, `> summary` (`seo.llms_txt.summary`, else the default
description), the editor's notes without headings, the contacts (phones,
emails, address, opening hours, social links), one `## section` per base
page type with the live indexable pages (`seo.llms_txt.types`, empty = all;
up to `seo.llms_txt.per_type`, default 50: articles newest first, others
home first, then by depth, picked from the type's first 1000 pages by sort
order), the host's sections, `## Optional` with the
sitemap and the file in each other frontend language. `loadPage()` adds
`Link: <…/llms.txt>; rel="describedby"` for the page's locale to `headers`
while llms.txt is on. A host section is `{ title(locale), links(locale, site) }`
returning `{ name, url, notes? }`; a relative `url` is made absolute.

```ts
const docsSection: LlmsTxtSection = {
	title: () => "Docs",
	links: () => [{ name: "API", url: "/docs/api", notes: "REST reference" }],
};
```

The same tab can add `Content-Signal: search=yes, ai-input=yes, ai-train=no`
(contentsignals.org) to robots.txt, each signal switchable; off by default.

### Markdown for agents

Once an editor turns on *Serve pages as markdown* (SEO settings → *For
AI*, `seo.markdown.enabled`), `cms.handle()` answers a live page as
markdown at its path plus `.md` (`/about.md`, `/uk/about.md`, `/index.md`
for the home page) and to a request whose `Accept` ranks `text/markdown`
above `text/html` (a tie goes to the type listed first; `*/*` is HTML; an
Inertia visit never). Not while indexing is off; never a draft. A path
without a live page, or a request that did not ask, is left to the host
(`null`), so its 404 and HTML answer as before; a redirect answers as for
the HTML path. `createCms({ markdown: { enabled: false } })` turns it off.

The document: YAML frontmatter (title, description, url, lang, image,
alternates), `# heading`, summary, body, every block, the page type's part,
`## About {site}` (the notes for AI and the llms.txt contacts) and the
page's JSON-LD graph as a `json` block. Headers: `text/markdown;
charset=utf-8`, `Vary: Accept`, `Link: <canonical>; rel="canonical"`,
`X-Robots-Tag: noindex` for a noindex page, `Content-Signal` while that is
on. `loadPage()` adds `Vary: Accept` and a `Link` to the `.md` to
`headers` while markdown is on: pass them on, and keep a page cache
keyed on `Accept`. llms.txt then links the `.md` versions.

A block shapes its part with `toMarkdown(props, ctx)`: a string replaces
the fallback (the text of its translatable fields, a repeater row by row),
`""` leaves it out, `null` keeps the fallback. A page type's
`toMarkdown(page, ctx)` adds text after the blocks; by default a type with
`allowedChildren` lists its live indexable children. `ctx` carries `page`,
`locale`, `url`, `fields`, `site`, and helpers that escape stored text:
`text`, `escape`, `heading(text, level = 2)`, `link`, `list`, `richtext`,
`pageItem(page)`, `children(limit)`.

```ts
Faq: {
	form: (s) => [...],
	toMarkdown: (props, ctx) =>
		[ctx.heading(props.heading), ...props.items.map((item) => `${ctx.heading(item.question, 3)}\n\n${ctx.text(item.answer)}`)].join("\n\n"),
},
```

### Access

`authorize(user, ability)` gets the panel user and a section ability, by
the PHP names: `tbtop-cms.pages` (pages and the page editor),
`tbtop-cms.submissions` (inbox), `tbtop-cms.redirects`, `tbtop-cms.design`
(layout and shared blocks; also Share and adding a shared block in the page
editor), `tbtop-cms.languages`, `tbtop-cms.settings` (site, SEO,
notifications) and `tbtop-cms.audit` (the audit log). A false hides the section from the nav and answers 403 to
its pages and to every form, action, table and upload on them. Without
`authorize`, `auth.authorize` decides; without both, every section is open
to any panel user.
`tbtop-cms.users` gates the Users page an auth adapter adds.

### Users and sign-in (`@tbtop/cms/better-auth`)

The core asks the host for a panel user (createAdmin's `session` or `user`)
and `authorize`. `@tbtop/cms/better-auth` answers both, passed once as
`createCms({ auth })`, from a Better Auth
instance with email and password and the admin plugin (`better-auth` is an
optional peer dependency):

```ts
import { cmsAccess, cmsAuth, cmsRoles } from "@tbtop/cms/better-auth";
import { betterAuth } from "better-auth";
import { admin as adminPlugin } from "better-auth/plugins";

export const auth = betterAuth({
	database: { db, type: "sqlite" }, // the CMS's Kysely instance; on D1 pass the binding itself (`database: env.DB`)
	emailAndPassword: { enabled: true },
	user: { modelName: "tbtop_users" }, // and session, account, verification
	plugins: [adminPlugin({ ac: cmsAccess, roles: cmsRoles })],
});
const cms = createCms({ db, blocks, auth: cmsAuth(auth) });
await migrateCms(db, { dialect: "sqlite", userKey: "string" }); // Better Auth's ids are strings
const admin = createAdmin({ ...cms.admin.options(), rootView }); // session, loginPath, the sign-in, Users and Profile pages come with it
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
- Pages: `login` (public; PHP's login screen: center layout without the
  chrome, the product mark, a card headed by the Site name, Remember me,
  a link to the site), and `users`, `users/new`, `users/{id}/edit`
  (Settings → Users): name, email, role, banned; add with a password, edit
  name and role (not one's own), set a new password (signs that user out
  everywhere; on one's own row, every other session), ban (signs out
  everywhere), unban, delete. Better Auth refuses banning or deleting
  oneself. And `profile`, linked at the top of the user menu, open to every
  role that gets in: one's own name, and a new password that needs the
  current one and signs every other session out (this one stays). Wrong
  current passwords count against `signInThrottle` per user. Email is shown,
  not edited. `cmsAuth(auth, { profile: false })` (or `defaultAuth`'s
  `adapter: { profile: false }`) leaves the page and its link out, e.g.
  behind SSO.
- Forgotten password: there is no email reset. Another admin sets a new
  password on the Users page. With no admin left to do it, delete the admin
  users (or demote them) and boot with `TBTOP_ADMIN_EMAIL` /
  `TBTOP_ADMIN_PASSWORD`: the boot creates an admin while the site has none.
- Sign-in attempts are throttled per client and email (`signInThrottle`,
  default 5 a minute, `false` turns it off; a success clears the count):
  Better Auth's own rate limit covers only its HTTP routes, and every
  attempt costs a password hash. In memory, like `forms.throttle`; the
  client comes from `forms.clientIp` or the proxy headers. With no proxy in
  front, pass `forms.clientIp`: otherwise a client sets its own
  `X-Forwarded-For` and gets a fresh count on every attempt (see Forms).
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
opens it, and the settings object never carries them. Without `settingsKey` a
secret cannot be saved; a value sealed with another key, or stored in plain
text, reads as the fallback with a `settings.secret_unreadable` warning.
Changing the key loses the stored secrets. The mail transport and the
Telegram bot token are host config, not settings.

`PageProps` is typed as JSON (`Jsonify`), so it goes through a framework
loader as it is: in TanStack Start, return it from a `createServerFn` handler.
A server function answers on its own URL during client navigation, so build
the page request from the route path (see `workbench/src/server/page.ts`):
pass the path (`location.href` of the router, which has no origin) and
resolve it against the caller's URL, never an absolute URL from the client.
Keep `@tanstack/react-start/server` imports in server-only modules
(`src/server/*`): a route component that imports it breaks the client bundle.

`seedFixtures(cms, demoFixture)` seeds a declarative fixture through the
package's own write paths (slugs, shared references). It runs once: a
database with pages is left alone. A fixture language marked `default` takes over
from the installed one (which stays, non-default: pass `defaultLanguage` to
`migrateCms` to install the right one). A page's `body` per locale is plain
text (blank-line separated paragraphs) or a Lexical document (`{ root: … }`,
what the editor stores) for headings, lists and links.

A seed whose pages point at each other goes step by step in the host's own
boot, after `migrateCms`: each step gets the ids of the pages before it. The
writes below are the public seeding API; they take the package's write paths
(slugs, tree rules, `tbtop_page_refs`, revisions, audit). `isFreshSite(cms)`
is the guard `seedFixtures` uses: true while the site has no page.

```ts
if (await isFreshSite(cms)) {
	await seedFixtures(cms, { languages, settings }); // or set them yourself
	const site = cms.site();
	const catalog = await site.pages.create({ type: "category", name: { uk: "Каталог" }, status: "published" }, { slugs: { uk: "catalog" } });
	// Without slugs a page gets one from its name (transliterated), as in the editor.
	const bream = await site.pages.create({ type: "article", subtype: "product", parentId: catalog.id, name: { uk: "Лящ" }, status: "published" }, { slugs: { uk: "bream" } });
	await site.pages.update(catalog.id, {
		content: [{ id: "grid", kind: "ProductGrid", active: true, sort: 0, data: { products: [String(bream.id)] } }],
	});
	await site.layouts.save("header", [{ id: "nav", kind: "SiteNav", active: true, sort: 0, data: {} }]);
	const contact = await site.sharedBlocks.create({ name: "Contact", kind: "ContactForm", data: {} }); // a row's `shared: contact`
}
```

Stored values are the editor's: a pages field is a list of page ids as
strings, a link `{ type: "page", page: "<id>", label }`. The seed is neither
atomic nor resumable: run it from one process (a deploy step or one boot,
not every instance at once), and if a step fails, empty the database and
seed again, since `isFreshSite` is false once the first page exists.
Images go the same way: the seed copies the file onto the disk the upload
fields write to and stores its ref, as an upload would
(`await copyFile("seed/hero.jpg", "storage/seed/hero.jpg")`, then
`pages.create({ ..., image: "seed/hero.jpg" })`); `media` turns the ref into
a URL.

A block that renders a large image calls `ctx.mediaImage(ref)` in `resolve()`
instead of `ctx.mediaUrl(ref)`: `{ src, srcset, width, height, alt }` for
`<img srcset sizes width height>`. The srcset lists `imageLoader(ref, w)` at
640, 960, 1280 and 1920 below the original's width (from `media()`), then the
original; without a loader, a known width or a narrower step it is null and
`src` renders alone. An unknown ref is null, as with `mediaUrl`.

A list the editor does not pick (a blog index, the products of a category,
"latest three posts") is the host's own query, in its loader or route: the
page props carry no children, and a block's `resolve()` does not see the
request's `?page=`. `site.pages.query()` is a Kysely builder over live
`tbtop_pages` rows (trashed ones left out); `whereLive` adds the rule the
frontend uses (published, `published_at` passed, `unpublish_at` ahead), the
counterpart of PHP's `Page::scopeLive`, `pageFromRow` turns a row into a
`Page`, and `site.pageViews(pages, locale)` makes each one what `props.page`
is for an open page (name, heading, summary in the locale with the
default as fallback, body HTML, image URL) plus its `url`; `{ fields: true }`
adds the resolved custom fields, one resolve per page:

```ts
import { pageFromRow, whereLive } from "@tbtop/cms";

const site = cms.site(request);
const perPage = 10;
const current = Math.max(1, Number(new URL(request.url).searchParams.get("page")) || 1);
const children = site.pages.query().$call(whereLive).where("parent_id", "=", blog.id).where("subtype", "=", "journal");
const { total } = await children.select((eb) => eb.fn.countAll<number>().as("total")).executeTakeFirstOrThrow();
const posts = (await children.selectAll().orderBy("published_at", "desc").orderBy("id", "desc")
	.limit(perPage).offset((current - 1) * perPage).execute()).map(pageFromRow);
const cards = await site.pageViews(posts, locale); // [{ id, name, summary, url, image, … }]
// pages: Math.ceil(Number(total) / perPage)
```

## Collections

A collection is a kind of record with no page of its own: reviews, team
members, phone numbers. Its fields are code; its entries are `tbtop_entries`
rows, edited on the admin's Collections page and read on the site through a
block's `collection` field or `site.collection(key)`. A record with its own
URL, SEO and blocks is a page type instead.

```ts
import { block, collection, defineSite, f } from "@tbtop/cms/site";

const reviews = collection(
	{ author: f.text().required(), text: f.textarea().translatable(), services: f.pages("service") },
	{ label: "Reviews", singularLabel: "review" }, // titleField, relatedField, resolve
);

export const site = defineSite({
	collections: { review: reviews },
	blocks: {
		Reviews: block({
			reviews: f.collection(reviews), // { id, author, text, services }[]
			featured: f.collection(reviews, { single: true, modes: ["manual", "latest"] }), // one or null
			phones: f.collection("phones", { max: 3 }), // a built-in, by key (untyped fields)
		}),
	},
});
```

The editor picks the mode per block: `all` (every published entry, the
editor's order), `latest` (the newest N, default 3), `related` (the entries
whose `pages` field, `relatedField`, picks the page rendered; offered only
when there is one) or `manual` (picked by hand, in that order). `max` caps
every mode; `modes` limits the choice, the first being the default. The value
is stored as `{ mode, limit?, ids? }` and checked by the `collection_value`
host rule (`cms.admin.options()` passes it).

`site.collection("review", locale?)` (and `ctx.collection(key)` in a
resolver) gives an `EntryList`: `all()`, `first()`, `take(n)`, `latest(n)`,
`relatedTo(page)`, `only(ids)`, `where(field, value)`, all in memory. A
published collection is resolved once per locale and request, and through the
content cache on the frontend; every entry write through the CMS (and a drag
reorder in the admin) bumps it. Collections are for hundreds of entries, not
tens of thousands: there is no SQL filtering on entry fields.

The low level is `createCms({ collections: [{ key, label, form: (s) => [...] }] })`
and `collectionField("reviews").of("review")` in a block's `form()`.

Admin: `{prefix}/collections` (one menu item under Content) shows every
collection as a tab, the built-ins first: its entries (search, drag reorder,
delete) and an "Add" modal; `?tab[collections]={key}` opens a tab.
`{prefix}/collections/{key}/{id}/edit` edits one entry (draft or published,
optimistic lock); both answer to `tbtop-cms.pages`. The
field's client is the `collection` kind of `@tbtop/cms/admin-client`; its
picker posts to `{prefix}/collections/{key}/entry-search/select-options/entry`.

**Built-ins.** `phones` (number), `emails` (address) and `socials` (name,
short label, link) are always there, their tabs first. The first published
phone and email are the identity's `telephone` and `email`, and `sameAs`
lists the social links; the identity's address, hours and coordinates stay
in SEO settings. They replace the `site.contacts.*` settings: re-enter those
values as entries (there is no data migration). `site.contacts` reads them
(`phones()`, `emails()`, `socials()`), and the settings object carries the
same lists as `phones`, `emails` and `socials` (`props.cms.settings.phones[0]`
in a header). A block can also pick them with a `collection` field.

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
- A host `resolve()` that needs more of the picked pages (a product's price
  on a catalog card) asks `ctx.pages(input.products, { fields: true })`: the
  same refs, each with the page's own columns as `site.pageViews` gives them
  (`summary`, `image`, `heading`, `body`…) and `fields`, its resolved custom
  fields as its own props carry them (`Record<string, unknown>`: the picks
  may be of any type, so narrow them). Any other request-scoped read
  (settings, `pageViews`) goes through `ctx.site`. It is a convenience: one fields resolve per page,
  one level deep (inside those resolvers `fields: true` gives `{}`). For a
  long list, or columns the props never carry, query the database yourself
  on top of `ctx.pages()`, which has already dropped drafts and built the URLs:

```ts
resolve: async (input, ctx) => {
	const refs = await ctx.pages(input.products); // live, in this locale
	const rows = await db.selectFrom("tbtop_pages").select(["id", "fields"])
		.where("id", "in", refs.map((ref) => Number(ref.id))).execute(); // raw stored JSON
	// merge rows into refs by id
},
```
- For "the form, plus a key or two", set `map` instead of `resolve`: the
  package resolves the form, and `map(resolved, input, ctx)` returns the keys
  to add or replace, typed as `Partial` of the catalog props (no
  `resolveForm` call, no cast). A block sets `resolve` or `map`, not both.

```ts
Pricing: {
	form: (s) => [s.text("title").translatable(), pagesField("plans").of("article", "plan")],
	map: async (_resolved, input, ctx) => ({ plans: await ctx.pages(input.plans, { fields: true }) }),
},
```
- On save the admin checks `link_shape` (type, target format, an existing
  page, label locales and length) and `page_ref` (each id exists) as host
  rules: `cms.admin.options()` passes them (`cms.admin.hostRules` when
  wiring createAdmin by hand), or a save with these fields fails on an
  unknown rule.
- The pages field is a plain async multi-select on the wire, so the stock
  admin client renders it. The link field is the custom `link` kind: the
  admin bundle imports its client from `@tbtop/cms/admin-client` (below),
  or the client shows its unknown-field placeholder. Its page picker posts to
  `{adminPrefix}/link/page-search/select-options/page`, an admin page the
  CMS adds to `cms.admin.pages`, open to admins with `tbtop-cms.pages` or
  `tbtop-cms.design` (the sections with link fields).

```tsx
// The admin bundle's entry. Peers: react 19 and @tbtop/inertia-admin (optional for the other entries).
import { collectionFieldClient, linkFieldClient } from "@tbtop/cms/admin-client"; // defineFieldClient(…): the import registers them
void [linkFieldClient, collectionFieldClient];
```

The CMS admin pages (the login screen among them) carry Tailwind classes the
admin client's stylesheet lacks, so the admin bundle's CSS scans the package:

```css
@import "@tbtop/inertia-admin/styles.css";
@source "../node_modules/@tbtop/cms/dist"; /* relative to this file */
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
import { assembleSpec, CmsHead, CmsRenderer, cmsCatalog, cmsComponents, seoHead } from "@tbtop/cms/react";

export const catalog = cmsCatalog({ ...cmsBlocks, Faq: faqMeta });
export const { registry } = defineRegistry(catalog, { components: { ...cmsComponents, Faq } });

// TanStack Start: head: ({ loaderData }) => seoHead(loaderData.seo)
// Anything else on React 19: <CmsHead seo={props.seo} /> (title, meta and links hoist into <head>)
<JSONUIProvider registry={registry}>
	<CmsRenderer spec={assembleSpec(props.cms.layout.header)} catalog={catalog} registry={registry} />
	<CmsRenderer spec={props.render} catalog={catalog} registry={registry} showErrors={props.isPreview} />
	<CmsRenderer spec={assembleSpec(props.cms.layout.footer)} catalog={catalog} registry={registry} />
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
- The layout slots (`props.cms.layout.header/footer`) are block lists, as in
  PHP; `assembleSpec` turns one into a spec for `CmsRenderer`.
- `Hero` and `ContactForm` are unstyled (`cms-hero`, `cms-contact-form*`).
  `useCmsForm(action)` is the ContactForm's submit logic for a custom form
  block: JSON post, field errors, a plain form post without JS. A controlled
  form calls `submit(values)` (resolves true once accepted) instead of
  spreading `formProps`; `setError(field, message)` shows a client check's
  message like the server's, `clearError(field)` drops one field's message
  (say, as the visitor edits it), `reset()` goes back to idle.
- The Hero's CTA link is read the way a browser reads it (leading controls
  and spaces dropped, tabs and newlines removed): a relative link (`/contact`,
  `blog`, `#form`, `?ref=x`) or an http(s), `mailto:`, `tel:` or messenger
  (`tg:`, `viber:`, `whatsapp:`, `skype:`, `sms:`) link passes, any other
  scheme (`javascript:`, `data:`) does not. The editor refuses it, and a
  stored one resolves to no CTA, so the component renders only a safe href.

## Forms and notifications

A form block declares `submit` in its implementation: a `schema` for the
payload (any Standard Schema: zod, valibot, arktype) and an optional `handle`
for the host's own work (a CRM call). `ContactForm` ships with a schema and no
handler. Wrap it in `defineSubmit` to get `handle`'s payload typed from the schema:

```ts
submit: defineSubmit({
	schema: z.object({ name: z.string().max(200), email: z.email().max(200) }),
	handle: async (payload) => crm.lead(payload.email), // { name: string; email: string }
}),
```

An optional `labels(locale, blockData, ctx)` names the fields in the team's
mail (`{ email: "Пошта", topic: "Тема" }`), in the readers' language rather
than the page's; `ctx.t` reads the CMS strings in it. Templates get the map as
`fieldLabels` (`fields` stays keyed by the raw names); a field without a
label keeps its key. `ContactForm` labels its fields from
`forms.contact.fields.*`.

`schema` may be a function of the block's data and the submit context
(`{ locale, page, t }`), for choices an editor sets or messages in the page's
language: `t("validation.required", { attribute })` reads the CMS strings,
Laravel's validation lines included. The block's props carry `action` and `honeypotField`; post the
fields there as JSON (`{ ok }`, 422 `{ errors }`, 500 `{ errors: { form } }`)
or as a plain form (303 back to the page with `cms_form_status`).

The schema gets the input trimmed, with blank strings as null (Laravel's
middleware). Its issues answer 422 by top-level field; one with no path is
`errors.form`. `unique`/`exists` are async refinements over the host's own
Kysely. A schema that throws answers `failed` (500 `{ errors: { form } }`,
logged as `form.submit.validation_failed`) and stores nothing.

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

Template overrides are Liquid (`{{ leadIdentity }}`, or `{{ leadName }}` and
`{{ leadContact }}` apart, `{% for f in fields %}`),
edited per type, format and locale in the notification settings and stored
in `tbtop_notification_templates`.

## Deploy

On a VPS (Node 22+ or Bun), run `tbtop-cms migrate` on each release and
`tbtop-cms scheduled` from cron every minute; both read the default export
of `cms.config.{ts,mts,js,mjs}` (or `--config`). The build only needs
`node:async_hooks` (from `@tbtop/admin`'s request context) outside the CLI.

On Cloudflare Workers, use a D1 Kysely dialect (D1 is SQLite), set
`compatibility_flags = ["nodejs_compat"]` (`node:async_hooks`, and `node:crypto` for
Better Auth, which `cloudflare(site)` installs by default; `nodejs_als` is enough
without Better Auth), add a `* * * * *` cron trigger
whose `scheduled()` calls `cms.tasks.scheduled()`, and pass
`{ waitUntil: ctx.waitUntil }` to `loadPage`. The CLI does not reach D1:
migrate on deploy with `tbtop-cms d1:migrations` and `wrangler d1 migrations
apply` (see "Migrations on Workers"; `d1Migrations(options)` returns the same
SQL for a host's own tooling). The files also fill Kysely's
`tbtop_cms_migrations`, so `migrateCms` on that database later finds the
baseline applied. Better Auth introspects through the `pragma_table_info`
join D1 refuses: give it the raw binding (`database: env.DB`), not the
Kysely instance.

Without the cron, `outbox` submissions stay `pending` and nothing is
notified (`cms.tasks.health()` warns). TanStack Start's default entry has
no `scheduled()`, so a Start host on Workers points `main` in
`wrangler.jsonc` at its own entry that keeps Start's `fetch`:

```ts
// src/server.ts
import handler from "@tanstack/react-start/server-entry";
import { getCms } from "./server/cms"; // the host's Cms for this env

export default {
	fetch: handler.fetch,
	scheduled(_controller: ScheduledController, env: Env, ctx: ExecutionContext) {
		ctx.waitUntil(getCms(env).tasks.scheduled());
	},
};
```


### Caching

`cache` keeps what every frontend request would query again: settings,
languages, published collections, the header and footer, the sitemap
(index, chunk count and chunks) and llms.txt, each per origin and locale
where it varies, as `CmsCache` does in PHP. Entries live under a content
version: any write through the CMS (a page, layout, redirect, shared block,
language, setting or collection entry, by an admin, a seeder or a host
script) bumps it, and every older entry stops being read. A scheduled page
needs no write and no cron: an entry never outlives the nearest
`published_at` or `unpublish_at` of a published page (`rememberLive`). KV
keeps an entry at least 60 seconds, so there a page can show up to a
minute late.

The store is written only on a miss and on a bump: a warm request reads
it and writes nothing. That matters on Workers KV, whose free plan takes
1000 writes a day: one edit costs the bump plus one write per entry the
next visitors miss. The scheduler's own bookkeeping (`system.*` settings)
does not bump. KV is eventually consistent, so an edit can take about a
minute to show in other regions.

The rendered HTML is the host's to cache, as in PHP. Cache only anonymous
`GET`s: an editor's preview and the admin carry cookies or a signed
preview link (`?signature=`), and `loadPage` adds `Vary: Accept` while
markdown is on; the locale is in the path. The Workers cache keys on the
URL and ignores `Vary`, so the recipe leaves a markdown request
(`Accept: text/markdown`) uncached. The package has no purge hook yet, so
keep the TTL short. On Workers:

```ts
// worker.ts: anonymous page HTML in the Workers cache for 60 s
async fetch(request: Request, env: Env, ctx: ExecutionContext) {
	const url = new URL(request.url);
	const cacheable =
		request.method === "GET" && !request.headers.has("cookie") && !url.searchParams.has("signature") && !(request.headers.get("accept") ?? "").includes("text/markdown");
	const key = new Request(url.toString());
	const hit = cacheable ? await caches.default.match(key) : undefined;
	if (hit !== undefined) {
		return hit;
	}
	const response = await app.fetch(request, env, ctx); // the host's own handler
	if (cacheable && response.status === 200 && !response.headers.has("set-cookie")) {
		const copy = new Response(response.clone().body, response);
		copy.headers.set("Cache-Control", "public, max-age=60");
		ctx.waitUntil(caches.default.put(key, copy));
	}
	return response;
}
```

On a VPS, a reverse proxy (nginx `proxy_cache`, Caddy, a CDN) does the
same with the same rules.

## What 0.0 has

- Schema and a baseline migration equal to the PHP migrations (SQLite,
  Postgres and MySQL column types), then the page title moved into
  `seo.title` (PHP #326), then `tbtop_entries` (collections).
- Collections: code-defined records in `tbtop_entries`, the `collection`
  block field (all / latest / related / manual), `site.collection()`, their
  admin page, and the built-in phones, emails and socials behind the site's
  contacts and identity (see "Collections").
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
  `ctx.referencedByPaged` / `ctx.childrenPaged` page an archive by `?page=`
  (tags, see Page types).
  `rebuildPageRefs(db, site.pageTypes)` rewrites the index.
- 404 log: `loadPage` counts each miss per locale and path (scanner probes
  in `notFound.ignore` and redirected paths left out); pass
  `{ waitUntil: ctx.waitUntil }` on Workers to write it after the answer.
  `seo.not_found.enabled` and `retention_days` are in the SEO settings,
  `cms.tasks.pruneNotFound()` prunes, and the admin's 404 log turns a miss
  into a redirect or ignores it.
- Cache: with `cache: { store: memoryCache() }` (one server) or
  `kvCache(env.KV)` (Workers), the frontend's settings, languages,
  collections, header and footer, sitemap and llms.txt go through a
  content-versioned cache (`ttl` default 3600 s, capped at the next
  scheduled publish). Admin reads stay live and every write through the CMS
  bumps the version; a write made around the CMS shows once the entry
  expires. See Caching.
- Audit log: a write to a page, redirect, language, shared block, layout or collection entry
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
  `d1:migrations` (no config: SQL files for wrangler),
  `doctor`, `scheduled`, `deliver`, `not-found:prune`, `page-refs:rebuild`, `seo:snapshot`,
  `audit [--type page] [--id N] [--limit N]`, `audit:prune [--days N]`
  (Bun runs a `.ts` config as is; Node needs a `.js` one or a TS loader).
- Blocks: `defineBlocks` catalog, `implementBlocks` server half, the
  optional Hero and ContactForm (`cmsBlocks`/`cmsBlockImpls`: nothing is
  registered unless the host spreads them in, as PHP's `blocks.types`), the PHP resolver rules (sort, inactive, unknown and
  broken shared rows skipped, locale flattening with fallback, FormResolver by
  field kind), resolved props validated against the catalog's zod schema
  (a mismatch is dropped with a warning; `strictBlocks: true` throws
  `BlockResolutionError` instead, in any environment). Valid props ship as
  resolved, and a `.default()` fills a key the resolver left out; a
  `.transform()`'s output is not shipped: the browser validates the props
  again and runs it there (the server parse runs it too, so keep it pure).
- Frontend: locale routing (default prefix 301, other locales prefixed),
  live/schedule scope, preview, page props and the shared `cms` prop.
- SEO: the full `seo` prop (title/description templates, Open Graph,
  alternates, x-default, verification tokens, the GTM container id), JSON-LD (WebSite,
  BreadcrumbList, the WebPage/Article families, CollectionPage, block nodes,
  site identity), robots.txt with an opt-in Content-Signal, llms.txt, markdown versions of pages, chunked sitemap with hreflang alternates.
- Forms: the submit endpoint, honeypot, a per-client rate limit, stored submissions with D1-safe
  claims, retries, outbox delivery; notifications over mail, Telegram and the
  admin bell with Liquid overrides and a delivery log.
- React: `@tbtop/cms/react` with the SEO head, the block renderer and the
  built-in blocks.
- Admin: the dashboard at `/{prefix}/dashboard` (page, inbox and layout
  tiles trended over 14 days, leads-by-status and daily submission charts
  (days in the config's `timezone`, UTC by default),
  the latest pages and submissions; the page parts need
  `tbtop-cms.pages`, the inbox parts `tbtop-cms.submissions`, the layout tile
  `tbtop-cms.design`; the System section, with host metrics and versions,
  shows to every admin, as in PHP), the SEO report (missing titles, descriptions,
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
