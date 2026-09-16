# SEO

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns everything under `Tbtop\Cms\Frontend\Seo\*`: the `seo` page
prop and its resolver, structured data, the sitemap, robots.txt, GTM
injection, the SEO audit/checklist, and the 404 log. Redirect mechanics live
in pages.md; installing the package and wiring `@cms/admin/blocks` /
`tbtop-cms::head` into the host's root view is wiring.md's job — this file
assumes that wiring exists and describes what it produces.

## The `seo` prop and how it is resolved

`SeoResolver::resolve(Page $page, string $locale, array $breadcrumbs = [])`
is the single source of truth for the `seo` prop shared with every CMS page
response. It returns one flat array — title, description, canonical, image
fields, OG/Twitter fields, article fields, `alternates`, `xDefault`,
`favicon`, verification tokens, and `jsonLd`. Nothing else in the package
re-derives these values for rendering; `SeoChecklist` and `SeoAudit`
re-derive only the *cheap* cascades (via `PageOwnSeo`) to avoid paying for a
full `resolve()` per page in a loop.

Field-by-field:

- **title** — `SeoTemplates::title($page->type, $locale)` picks a template:
  per-type (`seo.type_templates.{type}.title`) first, else site-wide
  (`seo.title_template`). An empty template falls back to the legacy
  `{title} {seo.title_suffix}` behavior (`SeoResolver::titleWithSuffix()`) —
  existing sites with no template configured render byte-identical titles.
  The `{title}` placeholder is the *own* title cascade: `seo.title` →
  `page.title` → `page.name` (`PageOwnSeo::title()`). Templates also support
  `{site_name}`, `{sep}` (`seo.title_separator`, default `—`), `{parent}`
  (immediate breadcrumb ancestor), `{date}` (published date, only when the
  page `isLive()`). `SeoResolver::cleanupRendered()` collapses whitespace and
  strips dangling separators left by an empty placeholder — one template can
  serve both a top-level and a nested page.
- **description** — same two-rung template lookup
  (`seo.description_template` / per-type), wrapping the base cascade
  `seo.description` → `page.summary` → `seo.default_description`
  (`SeoResolver::description()`).
- **ownTitle / ownDescription** — the cascades *without* the site-wide
  filler rung (no template, no `seo.default_description`). These exist so
  "is this page's own content filled in" can be answered without a template
  making every title non-empty; only `SeoChecklist`/`SeoAudit` read them.
- **canonical** — `validCanonicalOverride($seo, $locale)` if the page's
  `seo.canonical` for this locale parses to an absolute `http`/`https` URL
  with a host, else `PageUrl::build($page->pathFor($locale), $locale)`. The
  override is re-validated at read time, not trusted from storage, because
  rows can predate the validation rule or be written directly. `og:url` and
  the sitemap's per-locale `loc` reuse this same value/method.
- **image (og:image, alt, mime)** — `ownImage` first: `seo.og_image`
  override, else `page.image` (`PageOwnSeo::namesImage()` /
  `ownImageDetails()`). If the page names *no* image at all, falls back to
  `seo.default_og_image`. A page that named a broken media id still gets
  nothing rather than silently substituting the site default — the editor's
  choice is honored even when it fails to resolve. `mime` is only kept when
  it starts with `image/` (the media library also stores PDFs etc.).
- **noindex** — `! $page->is_indexable || ! IndexingSetting::enabled()`.
  Draft/expired/unpublished pages are excluded from the frontend entirely
  (routing, not `noindex`) — this flag only covers a *live* page an editor
  marked non-indexable, or a site-wide indexing kill switch.
- **hreflang alternates** — one absolute URL per frontend-active locale for
  which the page has a slug (`Page::pathFor()`), built the same way the
  sitemap builds its per-locale `loc` (`SitemapEntryBuilder::alternates()`
  mirrors this exactly, including the canonical-override exclusion below).
  `xDefault` points at the default locale's alternate, if present.
- **robots (meta)** — driven by `noindex` only; there is no separate
  `robots` field beyond that boolean (rendered as `<meta name="robots"
  content="noindex">` when true, omitted otherwise). The same verdict also
  goes out as an `X-Robots-Tag: noindex` response header — see
  "Noindex header" below.
- **jsonLd** — see Structured data below.

## Where each value is edited

| Value | Source of truth | Override path |
|---|---|---|
| Page meta title/description | `page.seo` JSON column (`seo.title`, `seo.description`) | Page edit → SEO tab: *Meta title*, *Meta description* (`PageForm::seoFields()`) |
| Focus keyword | `page.seo.focus_keyword` | SEO tab: *Focus keyword* — drives `SeoChecklist`'s keyword-presence checks only, never rendered |
| OG title/description/image | `page.seo.og_title` / `og_description` / `og_image` | SEO tab → *Social* section (`og_image` is a plain scalar, not translatable — an image isn't per-locale) |
| Canonical override | `page.seo.canonical` (translatable) | SEO tab: *Canonical URL* (validated `url:http,https`) |
| Schema.org node type | `page.seo.schema.type` | SEO tab → *Schema* section, only present when the page type's `schemaTypes()` offers more than one key |
| Article author | `Settings` (`seo.identity.type` / `.name`) | SEO settings → *Identity* tab — same value used for the site-wide Organization/Person node |
| `is_indexable` | `page.is_indexable` column | Page edit sidebar → *Publication* card, not the SEO tab |
| Site title/description templates, separator, suffix | `Settings` (`seo.title_template`, `seo.description_template`, `seo.title_separator`, `seo.title_suffix`) | Admin → SEO settings → *Titles* tab |
| Per-page-type templates | `Settings` (`seo.type_templates.{type}.{title\|description}`) | SEO settings → *Type templates* tab, one pair per registered page type |
| Default description / default OG image | `Settings` (`seo.default_description`, `seo.default_og_image`) | SEO settings → *Titles* / *Social* tabs |
| Twitter card mode | `Settings` (`seo.twitter_card`) | SEO settings → *Social* tab (`auto` picks `summary_large_image` iff an image resolved) |
| Site identity (Organization/Person) | `Settings` (`seo.identity.*`) | SEO settings → *Identity* tab exposes type and profile links (sameAs) only; name/logo fall back to the site's own `site.name`/`site.logo` unless a `seo.identity.name`/`seo.identity.logo` value already exists in the database (legacy/scripted values still win) |
| Indexing master switch | `Settings` (`seo.indexing_enabled`) | SEO settings → *Indexing* tab — gates robots.txt, the sitemap route (404s when off), and every page's `noindex` |
| Sitemap chunk size | `Settings` (`seo.sitemap_per_chunk`), falls back to `config('tbtop-cms.seo.sitemap.per_chunk')` | SEO settings → *Indexing* tab; config is the fresh-install default only |
| Custom robots.txt lines | `Settings` (`seo.robots_extra`) | SEO settings → *Indexing* tab — lines claiming an owned directive (`user-agent`/`allow`/`disallow`/`sitemap`) are rejected at save time |
| GTM container id | `Settings` (`seo.gtm_container_id`) | SEO settings → *Integrations* tab (accepts bare id or the full install snippet) |
| Search-console verification tokens | `Settings` (`seo.verification.google` / `.bing`) | SEO settings → *Integrations* tab (accepts bare token or the pasted `<meta>` tag) |
| 404 log toggle / retention | `Settings` (`seo.not_found.enabled`, `seo.not_found.retention_days`) | SEO settings → *Not found* tab |
| Sitemap enabled at all, chunk-size fresh-install default, 404 noise patterns, 404 auto-prune schedule | `config/tbtop-cms.php` (`seo.sitemap.enabled`/`.per_chunk`, `not_found.ignore`, `not_found.auto_prune`) | Host config only — no admin UI |

All `Settings`-backed values are read through a single reader class
(`IndexingSetting`, `SeoTemplates`, `GtmContainerId`, `VerificationToken`)
rather than ad hoc `Settings::get()` calls scattered around — reuse those
readers if a host needs the same value outside the resolver.

## Server-rendered head

`resources/views/head.blade.php` (included by the host's root Blade view,
see wiring.md) reads `$page['props']['seo']` off the raw Inertia payload and
renders every tag (title, meta description, canonical, robots, favicon,
verification meta tags, hreflang `<link>`s, OG/Twitter meta, article meta,
JSON-LD `<script>` tags) with a `data-inertia="<key>"` attribute. Client-side,
`resources/js/cms/CmsPage.tsx` renders the identical set through Inertia's
`<Head>` with matching `head-key` props — Inertia's head manager recognizes
matching keys on hydration and updates in place instead of duplicating
(`<title>` is the one exception, matched by tag name). Both sides must stay
in lockstep on which keys they emit; changing one without the other
reintroduces duplicate tags on hydration.

`head.blade.php` renders **nothing** when SSR is enabled
(`tbtop-cms.ssr.enabled`) and the SSR gateway actually served this request —
in that case the gateway's own head output (produced by the same
`CmsPage.tsx` code) already covers everything, and `Inertia\Ssr\SsrState` is
consulted once per request (memoized) to make this check free. If SSR is
down, returns null, or is off, the Blade fallback renders exactly as if SSR
never existed — SSR failure never means "no head". It also renders nothing
when `$page['props']['seo']` is absent — the root view is reused by non-CMS
routes.

## Structured data (JSON-LD)

Two classes build everything the package emits automatically, assembled by
`SeoResolver::jsonLd()` into the `seo.jsonLd` list (rendered in that fixed
order — `head.blade.php` and `CmsPage.tsx` both key on array index, not
`@type`, so order must not change):

1. **`JsonLd::website()`** — a `WebSite` node (name + root URL), emitted
   whenever `site.name` is set.
2. **`JsonLd::breadcrumbList()`** — a `BreadcrumbList` from
   `Breadcrumbs::trailForSchema()` plus the current page as the final item.
   That trail is the page's ancestry with the **site root prepended**, so a
   top-level page still lists `Home › Page` and a nested one lists
   `Home › Category › Article`. Omitted only on the home page itself, and on
   a site with no seeded root page (`is_system` + no parent — `PageSeeder`),
   where it falls back to the plain ancestry and so needs two real levels.

   This is deliberately *not* the `breadcrumbs` prop: that one stays on the
   real ancestry, because it also feeds the `{parent}` template variable.
   Prepending home there would turn `{parent}` from empty into `Home` on
   every top-level page. A host rendering its own visible trail keeps adding
   its own "Home" link as before.

   The root crumb is labelled from the home page's `title`/`name` with the
   usual locale → default-locale fallback; `PageSeeder` writes those only in
   the default locale, so until an editor translates the home page the crumb
   shows the default-locale label in every locale.
3. **The page's own node(s)** — built by a `Tbtop\Cms\Frontend\Seo\Schema\
   SchemaTypeInterface` resolved through `SchemaTypeRegistry`
   (`Frontend/Seo/Schema/`), one node per page (`build()` returns a
   `list<array<string,mixed>>`, but every built-in returns exactly one).
   Which key a page gets is a two-step lookup, not a hardcoded `match` on
   `page->type` any more:
   - `PageTypeInterface::schemaTypes(): non-empty-list<string>` lists the
     keys a page type may publish, first element the default
     (`BasePageType` → `['WebPage','AboutPage','ContactPage','ItemPage']`,
     `ArticleType` → `['Article','NewsArticle','BlogPosting']`,
     `CategoryType` → `['CollectionPage']`).
   - `SchemaSelector` (`Frontend/Seo/Schema/SchemaSelector.php`) reads
     `page.seo.schema.type` (the only key left under `seo.schema` — the
     rest of the old structured-data form fields are gone, see below): a
     page whose type isn't registered in `PageTypeRegistry` gets `WebPage`;
     a stored key outside the type's `schemaTypes()` (or none stored at
     all) falls back to the first element; the resulting key is looked up
     via `SchemaTypeRegistry::get()`, which throws on an unknown key
     (host misconfiguration surfaces, no silent fallback).

   `SchemaContext` (`Frontend/Seo/Schema/SchemaContext.php`, built once by
   `SeoResolver::resolve()` and reused for both `openGraph()` and
   `build()`) carries `page`, `locale`, `source` (the page type's
   `source()`, null for content-only types), the same
   `name`/`description`/`url`/`image` inputs the rendered head tags use,
   and `author` (`array{'@type': 'Organization'|'Person', name: string}|null`,
   derived from the site identity settings, null when the identity type is
   `none` or the name is empty). `SchemaContext::node(string $type): array`
   is the standard node every built-in starts from —
   `@context`/`@type`/`name`/`url` always, `description`/`image` if
   non-empty, `datePublished`/`dateModified` from `publishedAt()`/
   `modifiedAt()`. `publishedAt(): ?string` is gated on
   `published_at !== null && isLive()`; `modifiedAt(): ?string` is
   unconditional on `updated_at`.

   `SchemaTypeInterface::openGraph(SchemaContext $ctx): OpenGraph` drives
   `SeoResolver::resolve()`'s `ogType`/`articlePublishedTime`/
   `articleModifiedTime`/`articleAuthor`. `OpenGraph`
   (`Frontend/Seo/Schema/OpenGraph.php`) is a value object with `type`,
   `publishedTime`, `modifiedTime`, `author` (`OpenGraph::website()` sets
   `type` to `'website'` and leaves the rest null) — `page->type === 'article'`
   is not the check, each schema class owns its own Open Graph mapping.

   Built-ins: `WebPage`/`AboutPage`/`ContactPage`/`ItemPage` (plain node, no
   OG article semantics, `openGraph()` returns `OpenGraph::website()`);
   `Article`/`NewsArticle`/`BlogPosting` (`openGraph()` returns type
   `article` with `publishedTime`/`modifiedTime`/`author` from the context,
   node adds `mainEntityOfPage` and, when `$ctx->author` is set, `author`);
   `CollectionPage` (`hasPart` listing up to 100 live, indexable children,
   one query spent only for categories, `openGraph()` returns
   `OpenGraph::website()`). A host adds a class implementing
   `SchemaTypeInterface` via `config('tbtop-cms.seo.schema_types')` or
   `SchemaTypeRegistry::extend()` (package convention, same before-boot
   timing as `PageTypeRegistry`).

   **Article author**: not a per-page field any more. `SchemaContext::$author`
   and, through it, the Article node's `author` and
   `articleAuthor`/`article:author` all come from the site identity settings
   (`seo.identity.type` + `.name`): `organization` →
   `{"@type":"Organization","name":...}`, `person` → `Person`, `none` or an
   empty name → no author at all. This is the exact type mapping
   `JsonLd::identity()` already applies to the standalone
   identity node (below) — the two are meant to name the same entity.

   Removed with no migration of stored data: `ProfilePage` and its
   `profile_*` fields, `schema.article_type`
   (Article/NewsArticle/BlogPosting is now chosen the same way as the
   WebPage family, via `schemaTypes()` + the `schema_type` select), and
   `schema.author`. A row's stale `seo.schema.*` keys from before this
   change are simply ignored.
4. **Identity** (`JsonLd::identity()`) — an `Organization` or `Person` node
   from `seo.identity.*` settings (type/name/logo/sameAs), or nothing when
   `seo.identity.type` is `none`. Name and logo fall back to the site's own
   `site.name`/`site.logo` when no `seo.identity.name`/`seo.identity.logo`
   is set.

**What the host cannot add**: there is no hook or filter to append nodes to
this list beyond registering a new `SchemaTypeInterface` key, and
`SeoResolver`'s return shape and `SitemapController`/`RobotsController` are
not extendable from outside. Blocks the page renders (FAQ accordions,
service details, article body content, etc.) contribute **nothing** to
`seo.jsonLd` — the resolver never inspects block content, and there is no
`Block::toSchema()` yet (block-level schema is a later addition, not
available today — see recipes.md for the current workaround).

**Host pattern for block-derived schema** (what both real hosts that needed
it — divotek_com_ua, autofocus — actually built; not shipped by the
package): a host-owned middleware shares a *separate* `schema` Inertia prop
built from the same block-resolution the page controller already does, and
the host's own `CmsPage.tsx` renders it as a second `<script
type="application/ld+json">` tag alongside the package's `seo.jsonLd`
output. Shape, from `divotek_com_ua`:

- `app/Http/Middleware/AttachSchemaGraph.php` — registered on the frontend
  route group, resolves the current page via `PageResolver` + resolves its
  blocks via `BlockContentResolver`, calls `SeoResolver::resolve()` for the
  base title/description/canonical/image, and does
  `Inertia::share('schema', fn () => $this->builder->build(...))`.
- `app/Cms/Seo/SchemaGraphBuilder.php` — assembles a `@graph` array: one
  site-wide `Organization` node plus, per block, whatever
  `app/Cms/Seo/BlockSchema.php` maps that block `kind` onto (`Faq` →
  `FAQPage`/`Question`, `ArticleDetail` → `Article`, `ServiceDetail` →
  `Service`/`Offer`, anything else → `null`, dropped).
- The host's `CmsPage.tsx` renders it as its own `<script>` tag, **not**
  merged into `seo.jsonLd` — two independent structured-data script tags on
  the page is valid and simpler than trying to splice into the package's
  array.
- **Escaping**: when hand-building this script tag, escape `<` in the
  serialized JSON (`JSON.stringify(graph).replace(/</g, '\\u003c')` /
  PHP `json_encode(..., JSON_HEX_TAG)`) so a block's text content
  containing `</script>` cannot break out of the tag — the package does this
  in both `head.blade.php` (`JSON_HEX_TAG`) and `CmsPage.tsx`
  (`.replace(/</g, "\\u003c")`); a host-built script tag must do the same.

## Sitemap

Routes (`Cms::seoRoutes()`, called from `frontendRoutes()`): `sitemap.xml`
always serves a **sitemap index** (never a bare urlset, even on a small
site — the shape a crawler sees never has to change as the site grows),
pointing at `sitemap-{chunk}.xml` chunks (declared before the catch-all page
route so a page whose slug collides cannot shadow a chunk). Both routes
`abort(404)` when `IndexingSetting::enabled()` is false, checked outside the
cache so a still-warm cache entry never serves a 404 site's sitemap.
`tbtop-cms.seo.sitemap.enabled` (config only) can drop the routes entirely.

**Chunking** (`SitemapEntryBuilder`): rows-per-chunk = URL budget ÷ locale
count, floor 1 page per chunk (never split one page's locales across
files, never paginate forever). The budget is `seo.sitemap_per_chunk`
(Settings) if set, else `config('tbtop-cms.seo.sitemap.per_chunk')`
(default 1000), clamped to the protocol's 50,000 ceiling either way.

**Included**: live, indexable pages
(`Page::query()->live()->where('is_indexable', true)->whereNull('error_class')`),
one URL entry per locale the page has a slug for. The `error_class` filter is
belt and braces — an error page is already seeded `is_indexable = false` and
the admin form force-keeps it that way (see `docs/host/ai/pages.md`'s "Error
pages" section) — but it means an editor can never re-enable indexing for one
by flipping that flag. Each entry carries
`lastmod` (page `updated_at`), a depth-derived `priority` (home = 1.0, else
`max(0.3, 0.8 - depth*0.2)` — Google ignores `<priority>` since 2015, so this
needs no editor input), the page's *own* image only (never the site default
— listing the default against every URL would misclaim it), and
`xhtml:link` hreflang alternates for every other locale plus `x-default`.

**Excluded**: draft/expired/unpublished pages, non-indexable pages, and —
per locale — any locale whose `seo.canonical` override resolves to a
different URL than that locale's own sitemap address
(`SeoResolver::validCanonicalOverride()`, same check `canonical` uses). A
canonical that merely restates the same URL (trailing-slash/case cosmetic
differences only) does **not** exclude the locale.

## robots.txt

`RobotsController` → `RobotsTxt::render()`. Two halves: an owned
`User-agent: *` group (`Allow: /` or `Disallow: /`, following
`IndexingSetting`, plus the owned `Disallow:` paths below when indexing is
on), then the editor's `seo.robots_extra` lines spliced
**inside** that same group (blank lines between them are stripped — a blank
line would end the group and orphan everything after it in a real
robots.txt parse), then a trailing `Sitemap:` line (only when indexing is on
*and* the sitemap route is enabled). Lines claiming an owned directive
(`user-agent`/`allow`/`disallow`/`sitemap`, case-insensitive) are rejected
at save time in `SeoSettingsPage::guardRobotsExtra()`, not silently
stripped — an editor who wrote a conflicting line gets a validation error
naming it.

### Disallowed paths

The owned group carries a `Disallow:` line per path in `RobotsRegistry`, in
this order: the admin prefix (`tbtop-cms.admin.prefix`, read per render so a
renamed panel stays covered) and `/login` (the redirect the package registers
when the host has no login route of its own), then
`tbtop-cms.seo.robots.disallow`, then every `RobotsRegistry::extend()`
closure. Paths are normalised to a single rooted form (`cart/` and `/cart`
collapse to one `Disallow: /cart`).

These lines appear only under `Allow: /` — below a site-wide `Disallow: /`
they add nothing. `Disallow` stays an owned directive, so a path cannot be
added through `seo.robots_extra`; a package adds its own from
`boot()`/`packageBooted()`:

```php
RobotsRegistry::extend(fn (): array => ['/_storefront']);
```

Unlike `BlockRegistry`, this registry is **not** boot-latched — it is re-read
on every render, so a late `extend()` still lands in the file.

### Noindex header

`PageController` sets `X-Robots-Tag: noindex` on a page response whose
resolved `seo.noindex` is true — the same value the meta tag uses, taken from
the props rather than recomputed. It therefore also covers the not-live case
(draft, scheduled, expired) that `SeoResolver` alone does not know about:
those are visible only through preview access, and the header keeps a leaked
preview URL out of an index.

**Install trap**: a static `public/robots.txt` shadows this route — see
wiring.md, "`public/robots.txt` must not exist".

## Verification tokens and GTM

`VerificationToken::google()`/`::bing()` and `GtmContainerId` both accept
either a bare token/id or the full pasted snippet (search console `<meta>`
tag, or Google's two-part GTM install snippet) — `extract()` mines the
value out regardless of what was pasted, `valid()` gates what actually
reaches rendered markup. Both extract-and-validate again at *read* time
(`fromSettings()`), not only on save, so a value written by a script or
directly to the database is still safe to render.

**Where GTM is injected**: `InjectGtm` middleware (registered in
`Cms::frontendRoutes()`, frontend routes only — admin never passes through
it), rewriting the response body *after* render: the loader `<script>`
right after `<head>`, the `<noscript>` iframe right after `<body>`. This is
deliberate — a fresh install gets GTM with zero root-view wiring beyond the
existing `tbtop-cms::head` include, and because injection is post-render it
survives regardless of SSR/CSR. It only touches full-page HTML GET
responses (`text/html`, non-Inertia-XHR, non-JSON, non-redirect) — the
sitemap XML, robots.txt, and the form-submit JSON endpoint share this same
route group and are never rewritten.

## SEO audit and per-page checklist

**`SeoChecklist::for(Page $page, string $locale)`** — rendered beside the
page editor's SEO tab (`PageForm::seoFields()` → `seoChecklistSection()`,
client component `resources/js/cms/admin/seoChecklist.tsx`, needs
`@cms/admin/blocks` imported — see wiring.md). Checks, each `pass`/`warn`/
`fail`, `required` ones affecting result-list appearance
(`title`, `description`, `indexable`, `canonical`, `duplicate_title`,
`duplicate_description`):

- **title / description** — presence only (`ownTitle`/`ownDescription`,
  pre-template), `fail` when empty. Length is not checked here — the
  SERP preview meter measures pixel width live as the editor types.
- **og_image** — `warn` when nothing resolved (own image or site default).
- **indexable** — `warn` (not `fail`) when a *published* page has
  `is_indexable = false` — a legitimate configuration (thank-you page,
  intentional duplicate), just worth surfacing.
- **canonical** — `warn` when the canonical's host differs from the site's
  own host (case-insensitive compare).
- **hreflang** — `warn` only when the page has a slug in *some but not all*
  frontend-active locales; fully absent from a locale is normal.
- **focus_keyword checks** (4, only when `seo.focus_keyword` is set for the
  locale) — presence of the keyword in the **rendered** title, rendered
  description, `heading`, and slug. `focus_keyword` itself warns when empty.
- **duplicate_title / duplicate_description** — compares this page's rendered
  title/description against every other page's rendered title/description
  (via `renderedTitle()`/`renderedDescription()`, breadcrumbs resolved
  lazily only if the template uses `{parent}`) — `warn` on a collision,
  including collisions a template that omits `{title}`/`{description}` can
  create even between pages with distinct own values. Empty short-circuits
  to `pass` (nothing to collide with). Both checks share a single cursor
  pass over the other pages rather than scanning twice.

**`SeoAudit::run()`** — the site-wide report (`SeoReportPage`, admin nav
group "content", gated behind the `tbtop-cms.redirects` ability). One query for every *live* page (`Page::query()->live()` — a scheduled or
expired page is excluded), then plain PHP, deliberately never calling
`SeoResolver::resolve()` per page (would build alternates/JSON-LD/every OG
field × pages × locales). Issues: `missing_title`/`missing_description`
(fail), `missing_translation` (warn — no own `title`/`name` in a locale,
fallback disabled), `duplicate_title`/`duplicate_description` (warn,
locale-scoped, built in one pass per locale over the already-loaded pages),
`missing_og_image` (warn), `noindex` (warn, locale-independent).

`SeoReportPage` renders as two tabs. **Overview**: a summary alert, four KPI
stat cards (pages scanned, pages with issues, failures, warnings — each with
a sparkline once at least two days of snapshot history exist), a donut chart
of issues by severity, a bar chart of issues by type, and a line chart of the
last 90 days of snapshot history (fail/warn/pages-with-issues). **Details**:
one collapsible per page with issues (fail-containing pages first, then by
issue count), each holding a list of that page's issues.

**Daily snapshots** (`tbtop_seo_audit_snapshots`, one row per calendar day,
`Tbtop\Cms\Seo\Models\SeoAuditSnapshot`) back the Overview sparklines and
history chart. `php artisan cms:seo:snapshot` runs the audit and
upserts/updates today's row via `SeoAuditSnapshotWriter` — it is **not**
auto-scheduled by the package, so a host wanting daily history needs its own
cron entry for it. As a fallback so history still accumulates without cron,
`SeoReportPage::view()` also writes/updates today's snapshot on every page
load — coarser (at most as often as the report is opened), but sufficient to
build a trend over time on a low-traffic admin.

## 404 log

`src/NotFound/*`. `PageNotFound` event → `RecordsNotFoundHit` listener,
deferred to `app()->terminating()` (a dead-URL visitor must never wait on a
write) and written via an atomic upsert keyed on `(locale, path_hash)` —
not select-then-create, which races under bot traffic. Recorded per hit:
path, locale, running `hits` count, last referer/user-agent (truncated),
first/last seen timestamps.

**Noise filter**: `NotFoundNoise::shouldIgnore()` drops a path before it is
ever counted — case-insensitive substring match against
`config('tbtop-cms.not_found.ignore')` (default: `.php`, `.env`, `wp-`,
`.git`, `.well-known`, `xmlrpc`, `.aspx`, `.cgi`), plus anything over 2048
chars. Config-only, no admin UI — it is a deployment-level scanner filter,
not a per-site editorial choice.

**Toggle and retention** live in Settings, not config:
`seo.not_found.enabled` (recording on/off, SEO settings → *Not found* tab —
an unset/null value reads as **enabled**, same "absent means on" rule
`IndexingSetting` uses) and `seo.not_found.retention_days` (default 90 when
unset or `< 1`, to avoid a blank field wiping the whole log on a scheduled
run). `cms:not-found:prune` deletes rows with `last_seen_at` older than the
retention window; scheduled daily by the package itself unless
`tbtop-cms.not_found.auto_prune` (config) is `false`.

The log keeps recording exactly as above even though a 404 now renders a
real page (see "Editable error pages" below) — `PageController::notFound()`
still dispatches `PageNotFound` before it throws, and the redirect lookup
still runs first and still wins over both the log and the error page.

## Editable error pages

`src/Frontend/Errors/*`. `Cms::errorResponse(Request $request, int $status):
Response` always returns a response, in three fallback layers:

1. The seeded DB page for that status's `ErrorClass` (`4xx`/`5xx` —
   `ErrorPageResolver`), rendered through the normal `Cms/Page` Inertia
   component — same pipeline as any other page, so it picks up the host's
   `SiteChrome` for free (there is no package Blade layout — see
   `docs/host/ai/wiring.md`). `seo.noindex` is forced `true` and
   `canonical`/`ogUrl` are forced `null`: an error page carries no slug (see
   `docs/host/ai/pages.md`), so `SeoResolver`'s own canonical would be a
   made-up `/`-rooted URL.
2. Missing/unpublished page (e.g. seeder not run yet): the same `Cms/Page`
   component with `render: []` and `page.heading` from
   `tbtop-cms::errors.{4xx,5xx}.heading` — never Laravel's stock error view.
3. **5xx only**: if rendering the page itself throws, a static Blade view
   (`resources/views/errors/fallback.blade.php`) with no DB/Inertia/Settings
   dependency, so a broken database or a broken block cannot take down the
   error page meant to report it.

**Auto-registration** (`CmsServiceProvider::wireErrorPages()`), gated by
`config('tbtop-cms.errors.handle')` (default `true`): registers a
`renderable()` on the host's exception handler for
`Symfony\Component\HttpKernel\Exception\HttpExceptionInterface`, whitelisted
by `config('tbtop-cms.errors.statuses')` (default `[404, 500, 503]`) — any
other status falls through to stock Laravel untouched. Also falls through
(returns `null`) when: the status is 5xx and `app.debug` is `true` (Ignition
needs the real exception), or the request path is under the admin prefix
(`config('tbtop-cms.admin.prefix')`), or the request expects JSON (an API
client keeps Laravel's stock JSON body). `PageController`'s own 404 catch-all
throws `NotFoundHttpException` like any other route, so it goes through this
same registration and obeys every gate above — `errors.handle = false` leaves
the CMS's own not-found handling to stock Laravel too.

**Maintenance mode**: a `503` served by Laravel's maintenance screen (`php
artisan down`) happens before the framework boots the request pipeline —
this package's exception handler registration is never reached, so a
maintenance page is unaffected by any of the above.

## Redirects, briefly

Redirects affect indexability (status code, external targets) but are
otherwise pages.md's topic. `Redirect.status_code` is a plain integer column
(auto-redirects written by `RedirectWriter` on a slug change default to
`301`); external targets are rejected unless their host is listed in
`tbtop-cms.redirects.allowed_external_hosts` (config, exact case-insensitive
match, no subdomain wildcarding).
