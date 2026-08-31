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
  content="noindex">` when true, omitted otherwise).
- **jsonLd** — see Structured data below.

## Where each value is edited

| Value | Source of truth | Override path |
|---|---|---|
| Page meta title/description | `page.seo` JSON column (`seo.title`, `seo.description`) | Page edit → SEO tab: *Meta title*, *Meta description* (`PageForm::seoFields()`) |
| Focus keyword | `page.seo.focus_keyword` | SEO tab: *Focus keyword* — drives `SeoChecklist`'s keyword-presence checks only, never rendered |
| OG title/description/image | `page.seo.og_title` / `og_description` / `og_image` | SEO tab → *Social* section (`og_image` is a plain scalar, not translatable — an image isn't per-locale) |
| Canonical override | `page.seo.canonical` (translatable) | SEO tab: *Canonical URL* (validated `url:http,https`) |
| Article author / Article subtype | `page.seo.schema.author` / `schema.article_type` | SEO tab → *Schema* section, article pages only |
| WebPage subtype + profile fields | `page.seo.schema.type` / `profile_*` | SEO tab → *Schema* section, static (non-article, non-category) pages only |
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
2. **`JsonLd::breadcrumbList()`** — a `BreadcrumbList` from the `breadcrumbs`
   prop plus the current page as the final item; omitted when there is only
   one item (a top-level page has nothing to list).
3. **`PageSchema::build()`** — the page's own object, one per page,
   type chosen by the page's own `type`, never editable directly:
   - `article` → `Article`/`NewsArticle`/`BlogPosting`
     (`page.seo.schema.article_type`, via `ArticleSchemaType`), with
     `mainEntityOfPage`, `datePublished`/`dateModified`, and `author` (only
     if `schema.author` is set — there is no automatic author source).
   - `category` → `CollectionPage`, with `hasPart` listing up to 100 live,
     indexable children (name + URL), one query spent only for categories.
   - anything else (static pages) → the `SchemaType` picker
     (`WebPage`/`AboutPage`/`ContactPage`/`ProfilePage`/`ItemPage`,
     `page.seo.schema.type`) — this is the only page kind with an editorial
     type choice, because article/category types are already facts the CMS
     knows. `ProfilePage` without a `schema.profile_name` silently downgrades
     to plain `WebPage` (Search Console flags a mainEntity-less ProfilePage
     as invalid). `name`/`description`/`image`/`url`/dates are always
     derived, never hand-entered — a page with every schema field left blank
     still emits correct, valid structured data.
4. **Identity** (`JsonLd::identity()`) — an `Organization` or `Person` node
   from `seo.identity.*` settings (type/name/logo/sameAs), or nothing when
   `seo.identity.type` is `none`. Name and logo fall back to the site's own
   `site.name`/`site.logo` when no `seo.identity.name`/`seo.identity.logo`
   is set.

**What the host cannot add**: there is no hook or filter to append nodes to
this list, and `SeoResolver`'s return shape and `SitemapController`/
`RobotsController` are not extendable from outside. A page's own object is
picked purely by `page->type`, and blocks the page renders (FAQ accordions,
service details, article body content, etc.) contribute **nothing** to
`seo.jsonLd` — the resolver never inspects block content.

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

**Included**: live, indexable pages (`Page::query()->live()->where('is_indexable', true)`),
one URL entry per locale the page has a slug for. Each entry carries
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
`IndexingSetting`), then the editor's `seo.robots_extra` lines spliced
**inside** that same group (blank lines between them are stripped — a blank
line would end the group and orphan everything after it in a real
robots.txt parse), then a trailing `Sitemap:` line (only when indexing is on
*and* the sitemap route is enabled). Lines claiming an owned directive
(`user-agent`/`allow`/`disallow`/`sitemap`, case-insensitive) are rejected
at save time in `SeoSettingsPage::guardRobotsExtra()`, not silently
stripped — an editor who wrote a conflicting line gets a validation error
naming it.

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
`missing_og_image` (warn), `noindex` (warn, locale-independent). Falls back
to a plain key/value list when `pdo_sqlite` is unavailable (the DSL table
needs an in-memory sqlite connection to render).

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

## Redirects, briefly

Redirects affect indexability (status code, external targets) but are
otherwise pages.md's topic. `Redirect.status_code` is a plain integer column
(auto-redirects written by `RedirectWriter` on a slug change default to
`301`); external targets are rejected unless their host is listed in
`tbtop-cms.redirects.allowed_external_hosts` (config, exact case-insensitive
match, no subdomain wildcarding).
