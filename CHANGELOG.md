# Changelog

## Unreleased

### Breaking — the built-in role system was removed

The package no longer ships roles. `CmsRole` (superadministrator /
administrator / editor), the `cms_user_profiles.role` column, and the eight
`Gate::define()` calls that backed them are gone. Admin access itself is
unchanged: a `cms_user_profiles` row, or a user model implementing `CmsAdmin`.

**Roles are now the host's responsibility.** Each admin page declares a
`tbtop-cms.*` ability, and the new `tbtop-cms.roles` config flag decides who
answers it. With the flag off (the default) no ability is declared and the gate
is never consulted; with it on, your app must define all eight gates, since an
ability the package does not define denies by default.

#### Upgrading

Existing installs have roles in `cms_user_profiles.role`. The included
migration drops that column. **Under the default `roles => false`, every user
who had admin access — including editors — gains access to every section**,
among them form submissions (lead PII), template editing, and global settings.

- To keep a separation of duties, set `tbtop-cms.roles => true` and define the
  eight `tbtop-cms.*` gates in your app **before** upgrading. See the README.
- To accept the flat model, no action is needed beyond running migrations.

`UsersPage` remains, reduced to granting and revoking access (later restored
to also create and edit users — see Added below); it no longer assigns roles.
`cms:admin:create`, `cms:admin:grant`, and `cms:admin:revoke` are unchanged.

### Added

- Server-rendered `<head>`: title, description, canonical, full Open Graph
  (`og:type/url/site_name/locale/locale:alternate` plus the existing
  title/description/image), Twitter Card, hreflang (+ `x-default`), favicon,
  and JSON-LD (`WebSite` + `BreadcrumbList`) are now rendered in blade on the
  initial HTML response, so crawlers and social-network bots (which don't
  execute JS) see them — previously these tags existed only in Inertia's
  client-side `<Head>`. Host apps must add
  `@include('tbtop-cms::head')` to their root view, above `@inertiaHead` —
  see the README's "Server-rendered `<head>`" section. Client-side navigation
  keeps rendering the same tags via `CmsPage.tsx`; the two are reconciled by
  Inertia's `data-inertia`/`head-key` matching so hydration never duplicates them.
  **Host action required:** delete `public/robots.txt` if it exists — Laravel's
  default scaffold ships one, and as a static file it shadows this package's
  dynamic `/robots.txt` route.
- Per-locale SEO: a page's meta title, description, focus keyword, OG
  title/description, and the new canonical override are translatable (locale
  tabs in the SEO tab); rows saved before this change keep serving their
  single value on every locale until re-saved. Site-wide, `seo.default_description`
  and the new `seo.title_template` are translatable the same way.
- Title template: `seo.title_template` supports `{title}`, `{site_name}`, and
  `{sep}` (from `seo.title_separator`); dangling separators from empty
  substitutions are stripped. An empty template keeps the legacy
  `seo.title_suffix` behavior byte-for-byte — the suffix setting still works
  but is no longer shown in the UI.
- Description template: `seo.description_template` (translatable) mirrors the
  title template with `{description}`, `{site_name}`, `{sep}` — applied to
  every page's resolved meta description; an empty template leaves
  descriptions untouched. og:description keeps falling back to the
  untemplated description, same as og:title.
- Helper hints under every SEO field — settings page and the page editor's
  SEO tab — explaining each field's fallback chain and where it is used. The
  verification hint now says the fields can stay empty when the site is
  verified another way (DNS, Google Tag Manager, Analytics).
- Canonical URL override is now editable in the page SEO tab (advanced) — and
  actually persists: previously a `canonical` key in the seo JSON was wiped on
  every save because the bag is rebuilt wholesale.
- Google Tag Manager: set the container ID in the SEO settings (paste the
  bare `GTM-…` id or the full install code — both normalize to the id) and
  both halves of Google's snippet are inserted on every frontend page per
  Google's rules — the loader `<script>` right after the opening `<head>`
  tag, the `<noscript>` iframe right after `<body>`. Injection happens in
  the `InjectGtm` middleware on frontend routes, so it works with zero host
  wiring and the admin panel never gets the snippet. Malformed ids are
  dropped at render time, never interpolated into the markup.
- Search-engine site-ownership verification: `seo.verification.google` and
  `seo.verification.bing` in the SEO settings' Analytics tab (paste the bare
  token or the full `<meta name="google-site-verification" …>`/
  `<meta name="msvalidate.01" …>` tag — both normalize to the token) render
  the corresponding meta tag on every frontend page, server-rendered and in
  Inertia's client `<Head>`. Empty by default; the helper hints added earlier
  in this section describe when the fields can stay that way.
- Identity JSON-LD: an `Organization`/`Person` object (name falling back to
  `site.name`, logo, `sameAs` profile links) emitted on every page when
  enabled in the SEO settings.
- `seo.twitter_card` setting: `auto` (default, large card when an OG image
  exists — the previous behavior), or force `summary`/`summary_large_image`.
- SEO fields in the page editor now show the computed inherited value as
  their placeholder (what will actually render if the override stays empty).
- Behavior fix: turning `seo.indexing_enabled` off now also emits
  `<meta name="robots" content="noindex">` on every page — previously it only
  affected robots.txt and the sitemap, and a blocked-but-linked URL could
  still be indexed.
- Server-side rendering for the cms frontend (opt-in): set
  `tbtop-cms.ssr.enabled` to `true` and the full page — head and body — is
  rendered server-side via Inertia SSR for cms frontend responses only; the
  admin panel is never routed through SSR, regardless of this flag. See the
  README's "Server-side rendering" section for the host recipe (`ssr.tsx`
  entry point, Vite `ssr` build input, `inertia:start-ssr`). Falls back to the
  existing blade head and client-rendered body when the flag is off or the
  SSR process is unreachable — a broken SSR process never means a missing
  `<head>`.
- `SeoResolver::resolve()` gained `siteName`, `ogType`, `ogUrl`, `ogLocale`,
  `ogLocaleAlternates`, `twitterCard`, `alternates`, `xDefault`, `favicon`,
  and `jsonLd` — additive; existing keys are unchanged.
- Article pages (`type = 'article'`) now render `og:type=article` plus
  `article:published_time`/`article:modified_time`/`article:author`, gated
  the same way as the matching JSON-LD dates/author (`PageSchema`) — a draft
  or expired article never advertises a publication date. `og:image:alt` and
  `og:image:type` are rendered for every page whose resolved og:image came
  from the media library and has an alt text / image mime respectively,
  alongside a matching `twitter:image:alt`. `SeoResolver::resolve()` gained
  `ogImageAlt`, `ogImageType`, `articlePublishedTime`, `articleModifiedTime`,
  `articleAuthor` — additive.
- The sitemap's built XML is now cached (respecting `tbtop-cms.cache.enabled`)
  with a TTL capped to the next page schedule transition
  (`published_at`/`unpublish_at`), reusing the same content-cache
  invalidation events as the rest of the frontend. `seo.indexing_enabled`
  is still checked per-request, never from the cache.
- `Tbtop\Cms\Cache\CmsCache::rememberLive()`/`rememberWithTtl()`/`defaultTtl()`
  and `Tbtop\Cms\Cache\NextScheduleTransition` (restored).
- `Tbtop\Cms\Admin\Events\CmsAccessGranted` and `CmsAccessRevoked` domain
  events, dispatched when a user's cms admin access is granted or revoked
  (from the Users page or the `cms:admin:grant`/`revoke` commands). A
  roles/permissions extension package can subscribe to seed or clean up its
  own per-user assignments.
- Users can now be created and edited directly from the Users page (a UI
  mirror of `cms:admin:create`'s assumptions), not just granted/revoked
  access.
- A protected owner: `tbtop_user_profiles.is_owner` marks the first admin of
  a fresh install (or, on upgrade, the earliest existing admin — backfilled
  by migration). The owner cannot be revoked or have their name/email/
  password edited by anyone but themselves. This is a protection only — the
  owner gets no extra section rights; that axis is still entirely the host's
  business via `tbtop-cms.roles`.
- **404 log**: a new "404 log" admin page (nav: Content, shares the
  `tbtop-cms.redirects` ability — the operator working it is the same one who
  manages redirects) records every frontend path that resolves to neither a
  page nor a redirect, deduplicated per (locale, path) with a hit counter,
  last referer/user agent, and first/last-seen timestamps. Recording is
  deferred to `app()->terminating()` (same pattern as the submission-notify
  listener) so a visitor hitting a dead URL never waits on the write, and
  obvious scanner noise (`.php`, `.env`, `wp-*`, `.git*`, `.well-known`
  probes, `xmlrpc`, `.aspx`, `.cgi`, configurable via
  `tbtop-cms.not_found.ignore`) is dropped before it reaches the counter. A
  row action creates a redirect straight from a logged path (writing through
  the same form `RedirectsPage` uses) and marks the hit handled. New setting
  on the SEO settings page: **Record missed paths** (on/off) and
  **Retention (days)**. Hosts that want automatic cleanup must schedule the
  new `cms:not-found:prune` command themselves — it is not scheduled by the
  package. **Host action required for package-config hosts:** none, the page
  registers automatically. **Hosts with a published `config/tbtop-cms.php`**
  must add `NotFoundLogPage::class` to `admin.pages` themselves, same as any
  other new page.
- **SEO checklist**: a read-only card of deterministic checks (meta title
  length, description length, OG image presence, indexable-but-published,
  canonical host mismatch, hreflang coverage, focus-keyword presence in
  title/description/heading/slug, duplicate title against other pages) now
  renders above the editable fields in the page editor's SEO tab, computed
  for the default locale. No scoring, no readability analysis — every line is
  a plain pass/warn/fail fact. A pixel-width live preview is intentionally
  not part of this: it needs `liveRegion` from an unreleased admin client
  version and is left for a later pass.
- **Per-locale SEO checklist and social (OG) preview**: the checklist now
  resolves every editor-active locale at once (same set the search preview
  already covers) and the client switches with the locale tabs, instead of
  only ever showing the default locale's checks. A new social preview card
  sits above the OG fields, live-rendered the same way the search preview is:
  image (1.91:1), host, title and description, resolved through the real
  `og:title`/`og:description` fallback cascade against the editor's unsaved
  input — so what an editor sees is what a link to the page would actually
  show when shared, not the raw field values. The checklist itself stays a
  page-load snapshot rather than a live region: its duplicate-title check
  runs a database query, and nothing else in it changes mid-keystroke either.
- **SEO column** in the Pages list: a `seo_state` badge (Missing / Needs work
  / Good) placed after the status column, computed in SQL via `selectRaw` so
  it costs nothing extra per row. Deliberately narrower than the editor's
  checklist: `name`/`title`/`summary` are translatable JSON-map columns, and
  checking one locale's slice of that JSON portably across sqlite/MySQL/
  Postgres would mean three divergent per-driver expressions — so the column
  reads only `is_indexable` plus whether `name`/`title`/`summary` were ever
  written to at all (SQL-NULL), not whether a specific locale's value is
  empty. See `PagesPage::seoStateSql()`'s docblock for the full reasoning;
  the per-page checklist above is the authoritative, locale-aware detail.
- Project passport files for the public repo: `SECURITY.md`,
  `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, and GitHub issue forms, plus
  README sections on versioning/releases and contributing/security.
- **Health checks**: `php artisan cms:doctor` and a new "Site health" admin
  page (Settings) run ten diagnostics for the most common install/config
  mistakes — missing panel or frontend routes, a root view missing
  `@include('tbtop-cms::head')`, host JS/CSS wiring heuristics (blocks import,
  admin page component, Tailwind `@source`), a `tbtop/admin` PHP/JS version
  mismatch, a static `public/robots.txt` shadowing the dynamic route, an
  unwritable media disk, notification channels enabled without their required
  settings, a scheduler heartbeat (new `everyMinute` schedule entry, so hosts
  whose cron never calls `schedule:run` find out), a suspicious `APP_URL` in
  production, and the owner/default-frontend-language invariants. Each check
  is isolated — one throwing does not crash the report — and every
  message/hint is a translation key resolved at render time, so both surfaces
  render in their own locale. `cms:doctor` exits non-zero only when a check is
  at error level; warnings never fail the run. Hosts with a **published**
  `config/tbtop-cms.php` must add `'site_health' => SiteHealthPage::class` to
  the `panel.pages` list themselves — a published config shadows the package
  default, so the page (and its route) does not appear until the entry is
  added.

### Changed

- `cms:admin:revoke` now refuses to revoke the owner, printing an explicit
  error and exiting with `FAILURE` instead of a false "revoked" success
  message.

### Fixed

- Self-revoke on the Users page is no longer bypassable on hosts whose user
  model has a non-integer key: identifiers are compared as strings, where an
  `(int)` cast previously collapsed every uuid/ulid to `0`.
- The Users page's empty state now carries a localized description instead of
  falling back to the admin client's built-in English hint, which mixed
  locales under a non-English admin.
- The GTM container ID helper now notes that the cms frontend is a
  single-page app, so pageview tags need a History Change trigger in GTM to
  count client-side navigation.
