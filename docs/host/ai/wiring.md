# Wiring

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns everything between the package and the host that is not
content: install sequence, frontend routes, the Inertia entries and page
components, shared props, `<head>`/SSR, `robots.txt`, and admin access.
Blocks, page types, content seeding, SEO settings, and notifications are
covered by their own sibling files (`blocks.md`, `pages.md`, `seo.md`,
`notifications.md`) — reference them by name here, don't duplicate them.

## Install sequence

```bash
composer require tbtop/cms
php artisan vendor:publish --tag="tbtop-cms-config"
php artisan admin:install
php artisan cms:install
php artisan cms:admin:create you@example.com
php artisan cms:doctor
```

What each step actually does:

- `vendor:publish --tag="tbtop-cms-config"` — publishes `config/tbtop-cms.php`.
- `admin:install` (from `tbtop/admin`, not this package) publishes three files
  this package's panel depends on and does not own: `resources/views/admin.blade.php`
  (root view), `resources/js/admin.tsx` (dedicated Inertia entry),
  `resources/css/admin.css`. Only writes files that don't already exist; pass
  `--force` to overwrite on a populated host.
- `cms:install` (`Tbtop\Cms\Console\InstallCommand::handle`) publishes config,
  runs migrations (`--skip-migrations` to opt out), and seeds the default
  language + home page (`LanguageSeeder`, `PageSeeder`). Skipping it is not a
  smaller install — it's a broken one: `cms:doctor` then reports three
  failures (missing tables, missing routes, missing content) instead of the
  one thing you actually skipped. `handle()` prints `nextSteps()` on success —
  frontend routes, the head include, the `@cms` alias, the admin-blocks
  import, Tailwind `@source`, deleting `public/robots.txt`, and adding the
  agent-docs pointer line to `AGENTS.md`/`CLAUDE.md` (see below) — because
  every one of those edits a file this package does not own (host build
  config, host root view, host Vite entry); writing them automatically would
  be a package silently mutating an application it doesn't understand. Every
  item is covered in its own section below.
- `cms:admin:create you@example.com` grants the first admin — see "Admin
  access and roles" below for what that means.
- `cms:doctor` (`Tbtop\Cms\Console\DoctorCommand`, backed by `HealthCheckRunner`)
  re-verifies the wiring against the filesystem/DB/config. Prefer it over
  re-reading this file after later changes — see the symptom table.

## Frontend routes (`Cms::frontendRoutes()`)

`Tbtop\Cms\Cms::frontendRoutes()` registers, in order: form submission
(`_cms/forms/{locale}/{page}/{block}`), `robots.txt`, `sitemap.xml` /
`sitemap-{chunk}.xml` (if `tbtop-cms.seo.sitemap.enabled`), then a catch-all
`Route::get('{path?}', PageController::class)->where('path', '.*')`. Call it
from `routes/web.php` — it does not wrap itself in the `web` middleware
group, so CSRF/session depend on the host's own route file already being in
that group.

**Call it last.** The catch-all matches everything after it registers, so a
host's own `/about`, `/contact`, `/pricing` routes 404 if declared afterward,
and `/` 500s until the CMS has a seeded home page to resolve.

**In package tests (workbench), `frontendRoutes()` is not registered.** The
workbench's `routes/web.php` skips it while `runningUnitTests()` is true, so
a Pest test hitting `GET /...` 404s even with everything wired correctly.
Register it explicitly in the test: `app(\Tbtop\Cms\Cms::class)->frontendRoutes();`
before the request.

**The `welcome`/`home` trap on a fresh `laravel new --react` app.** The
starter kit's own `Route::inertia('/', 'welcome')->name('home')` matches `/`
first and permanently shadows the CMS's seeded home page, `frontendRoutes()`
position notwithstanding — delete or rename it. But deleting it outright
breaks `npm run build`: the React starter kit's `auth-simple-layout.tsx`,
`auth-split-layout.tsx`, and `auth-card-layout.tsx` all call the
Wayfinder-generated `home()` helper unconditionally, and Wayfinder stops
exporting it the moment nothing is named `home`:

```
[MISSING_EXPORT] "home" is not exported by "resources/js/routes/index.ts"
```

Don't edit those three layouts. Keep the route name on a route that never
actually answers real traffic instead — declared **after** `frontendRoutes()`
so the catch-all wins dispatch for `/`, existing purely so `route('home')` /
`home()` still resolves:

```php
Cms::frontendRoutes();

Route::get('/', fn () => abort(404))->name('home');
```

`cms-starter-kit` shows the working alternative for a host that keeps a real
non-CMS `/`: an explicit host route declared **before** `frontendRoutes()`
wins over any page with the same slug (`routes/web.php`:
`Route::inertia('/', 'welcome')->name('home'); Cms::frontendRoutes();`) — no
`home()`-trap workaround needed because `home` is a real, answering route.

**`frontend.middleware` config.** `frontendRoutes()` reads
`tbtop-cms.frontend.middleware` (default `[SetLanguage::class]`) and always
appends `EnableSsr::class, InjectGtm::class, ShareCmsProps::class` after it —
those three are not configurable. Add to the list for locale/geo middleware
that must run before language resolution; don't remove the trailing three.

**The `login`-route ownership rule.** Admin routes other than the login page
carry `auth:{guard}`; Laravel's `auth` middleware redirects an unauthenticated
visitor to `route('login')`. `CmsServiceProvider::wireAdminLoginRedirect()`
registers `Route::redirect('login', "{$prefix}/login")->name('login')` —
**but only when nothing else already owns that name** (scanned directly off
`Route::getRoutes()`, not `Route::has()`, since that lookup's cache predates
host routes registered later in boot). A host shipping its own `login`-named
route — Fortify, Breeze, Jetstream, most starter kits — keeps it: a second
route under the same name would still lose `route('login')`'s URL generation
to whichever registered first while `GET /login` dispatches to whichever
registered last, and doubles a Wayfinder export, breaking the build. Nothing
is actually broken by this — `admin/login` still works visited directly, and
`redirect()->intended(...)` still lands the user on `/admin/dashboard` after
authenticating there. The only symptom is cosmetic: `/admin` shows the host's
login page, not the CMS's. Fix it (optional) by pointing the host's `login`
handler at a redirect to `admin/login`, or just link there directly.
`cms:doctor` does not catch this — its route check only confirms
`tbtop.cms.login-page` exists, which it always does once `tbtop/admin` boots.

## Inertia entries and page components

Two Vite entries, two audiences:

| Entry | Component | Who ships it |
|---|---|---|
| `resources/js/app.tsx` (host's own) | `Cms/Page` (config: `tbtop-cms.render.component`, default `Cms/Page`) | every public visitor |
| `resources/js/admin.tsx` (`admin:install`-published) | `admin/page` | admin users only |

**Why split.** The admin bundle (richtext editor alone: hundreds of KB) must
never reach public visitors. `admin.tsx`'s `resolve()` special-cases
`admin/page` inline (`if (name === 'admin/page') return AdminPage`) — no
on-disk component file needed for it.

**`Cms/Page` must exist as a real file.** Unlike `admin/page`, this package
cannot special-case `Cms/Page` inside the host's own `app.tsx` — that file
resolves every other page the host owns too (an explicit `resolve` glob, or
`@inertiajs/vite`'s default glob over `resources/js/pages/**/*.tsx` when
`app.tsx` declares no `resolve` at all — both look the component name up as a
literal file path). Skip the file and every frontend page 500s:
`Unable to locate file in Vite manifest: resources/js/pages/Cms/Page.tsx`.

**The norm: the host owns its own `resources/js/cms/CmsPage.tsx`, copied and
adapted from the package's `resources/js/cms/CmsPage.tsx`.** The README's
documented minimum is a plain re-export —

```tsx
// resources/js/pages/Cms/Page.tsx
export { default } from '@cms/CmsPage';
```

— pointing straight at the vendor copy via the `@cms` alias, and that's what
this package's own workbench host uses. But both real hosts surveyed
(divotek_com_ua, autofocus) instead keep a local copy at
`resources/js/cms/CmsPage.tsx` and re-export from **that**:

```tsx
// resources/js/pages/Cms/Page.tsx
export { default } from '@/cms/CmsPage';
```

The local copy adds a `SiteChrome` component rendering `cms.layout.header` /
`cms.layout.footer` (the `ShareCmsProps`-shared block lists — see "What the
frontend receives" below) around the page body, plus concerns the vendor file
has no reason to carry: a `ContactModalContext` provider, a `schema`/JSON-LD
prop, spacing that depends on which block kind opens the page. Neither host
declares a `@cms` Vite/tsconfig alias, because once `CmsPage.tsx` is local,
nothing else needs to reach into `vendor/tbtop/cms/resources/js/cms`.

**When the plain re-export is enough:** no persistent header/footer chrome
around CMS pages, or still at the bare-wiring stage (`cms-starter-kit`) — no
`SiteChrome`, no local `CmsPage.tsx`. **When to copy:** the moment the host
needs a persistent header/footer, site-wide providers, or any prop beyond
`render`/`seo`/`isPreview` — those mean editing what the client renders, and
the vendor file is not the host's to edit in place.

`CmsPage.tsx` imports `@json-render/react` (and transitively
`@json-render/core`, pinned to the same `^0.19.0` this package builds
against) — the host's build needs both resolvable the moment `Cms/Page.tsx`
pulls it in, copied or not: `npm install @json-render/core @json-render/react
@tbtop/inertia-admin`.

## The `@cms` alias

Only needed when host TSX imports something from the package's own
`resources/js/cms` directly — the admin blocks side-effect import below, and
(if using the plain re-export) `Cms/Page.tsx` itself. A host with its own
local `CmsPage.tsx`/`registry.tsx` (the copy-and-adapt norm above) may still
need it just for `@cms/admin/blocks`.

```ts
// vite.config.ts — a fresh laravel new --react scaffold ships no `resolve`
// block at all, so add the whole block rather than extending one.
resolve: { alias: { '@cms': path.resolve(__dirname, 'vendor/tbtop/cms/resources/js/cms') } },
```

```json
// tsconfig.json
{ "compilerOptions": { "paths": { "@cms/*": ["./vendor/tbtop/cms/resources/js/cms/*"] } } }
```

## Admin blocks side-effect import

A few admin pages render through custom node kinds whose renderers live in
this package (e.g. the search-preview block, which measures rendered pixel
width to switch desktop/mobile layouts — something no server-rendered
display block can do). Import the barrel to register them:

```tsx
// resources/js/admin.tsx — side-effect import, before createInertiaApp()
import '@cms/admin/blocks';
```

The barrel also registers the **`link` field client** (`$s->link()`'s type
switch, page picker, per-locale label row and inline validation). Without the import a `link` field
in the page editor renders as the unknown-kind placeholder, so a host using
link fields needs this wired even if it never touches the preview blocks.

**Must go in the admin entry, not `app.tsx`.** Putting it in `app.tsx`
instead ships the admin blocks (and their dependencies) to every public
visitor, defeating the entire bundle split `admin:install` sets up.
`JsWiringCheck` accepts `app.tsx` as a fallback for hosts wired before a
dedicated `admin.tsx` existed, checking `admin.tsx`/`.jsx`/`.ts` first, then
`app.tsx`/`.jsx`/`.ts` — but new installs should use `admin.tsx`.

**Known gap, not a documented alternative:** neither real host surveyed
(divotek_com_ua, autofocus) has this wired — both use a single `app.tsx`
fallback entry with no `@cms/admin/blocks` import and no local
`resources/js/cms/admin` directory, so their admin page editors render those
block kinds as the unknown-kind placeholder. Wire the import if the editor
needs to preview them correctly.

## Admin stylesheet (`@source`)

The admin client ships Tailwind v4 **source**, not compiled CSS — the host
pipeline compiles it. `admin:install` publishes `resources/css/admin.css`
with one `@source` line already in it, scoped to the host's own
`app/**/*.php`. Add two more so Tailwind also scans this package for the
classes **it** authors (PHP for the admin DSL — `->class()`, section `class`
options — TSX for the admin blocks above):

```css
/* resources/css/admin.css */
@import '@tbtop/inertia-admin/styles.css';

@source '../../app/**/*.php';
@source '../../vendor/tbtop/cms/src/**/*.php';
@source '../../vendor/tbtop/cms/resources/js/cms/admin/**/*.tsx';
```

Missing either line does not error — the affected classes silently compile
to nothing and those blocks/pages render unstyled.

Every admin-facing TSX in this package therefore lives under
`resources/js/cms/admin/` — that glob is the host's contract, so a component
placed outside it compiles without its utility classes.

## Vite build input

```js
// vite.config.ts
laravel({
    input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/admin.tsx'],
    refresh: true,
}),
```

Miss the `admin.tsx` entry and `admin.css` never compiles — silently absent
from `public/build/manifest.json`, no build error, panel loads unstyled.

## What the frontend receives

**Shared prop `cms`** (`Tbtop\Cms\Frontend\Http\Middleware\ShareCmsProps`,
registered by `frontendRoutes()`) — present on every frontend Inertia
response:

| Field | Type | Meaning |
|---|---|---|
| `cms.settings` | `array` | Site settings via `Settings::forFrontend()` (frontend-safe subset; see seo.md/notifications.md for the settings that live here) |
| `cms.locale` | `string` | Current request locale |
| `cms.defaultLocale` | `string` | Configured default content locale (`Languages::defaultLocale()`) |
| `cms.languages` | `list<{locale, name, is_default}>` | Active frontend languages (`Languages::frontendActive()`) |
| `cms.layout` | `array<string, list<ResolvedBlock>>` | Resolved `header`/`footer` block lists — cached across requests under the content version (`CmsCache::remember`) at this call site, not inside `LayoutResolver`, so the admin layout editor reads live data |

**Page props** (`Tbtop\Cms\Frontend\Http\Controllers\PageController::props`)
— the per-request Inertia props, in addition to `cms`:

| Field | Type | Meaning |
|---|---|---|
| `page.id`, `.type`, `.status`, `.name`, `.title`, `.heading`, `.summary` | scalars | Page identity/content fields, `.name`/`.title`/`.heading`/`.summary` already resolved to the request locale via `getTranslation()` |
| `page.image` | `string \| null` | Resolved media URL (page stores a media id, not a URL) |
| `render` | `Spec` (json-render) | The assembled page spec — resolved block content passed through `PageSpecAssembler`; see blocks.md for the block/envelope/resolved-block vocabulary |
| `seo` | `object` | Full SEO tag set (title, description, canonical, OG/Twitter, hreflang alternates, JSON-LD, `noindex`) — see seo.md for field-by-field detail; `CmsPage.tsx`'s `<Head>` and `head.blade.php` both render this same payload |
| `breadcrumbs` | `list<{label, path, url}>` | From `Breadcrumbs::forPage()` — real ancestry, no site root prepended |
| `path` | `string` | The resolved request path |
| `isPreview` | `bool` | `true` for draft/scheduled/expired pages reachable only via preview access — `seo.noindex` is forced `true` alongside it |

Preview access (`PageController::hasPreviewAccess`) is granted to a signed-in
admin (`CurrentAdmin::canAccess()` — not mere guard authentication) or a
request with a valid signed preview URL (`PreviewLink`); everyone else gets
the normal published-only lookup.

A page whose `type` is no longer registered in `PageTypeRegistry` still
renders its stored `content` — the type lookup is optional
(`$this->pageTypes->has($page->type) ? ... : null`). A source-backed type
(see pages.md) whose `source()` resolves to `null` (deleted / unpublished on
that channel) 404s the same as an unpublished page.

## Server-rendered `<head>`

Add the include to the host's root view (`resources/views/app.blade.php`),
**above** the Inertia head output — `@inertiaHead` or `<x-inertia::head>`,
both the same directive:

```blade
@vite(['resources/css/app.css', 'resources/js/app.tsx'])
@include('tbtop-cms::head')
<x-inertia::head>
    <title>{{ config('app.name', 'Laravel') }}</title>
</x-inertia::head>
```

`resources/views/head.blade.php` reads `$page['props']['seo']` off the
Inertia root view's own `$page` array directly (not a prop passed to the
include) and renders nothing when it's absent — safe on a root view reused by
non-CMS routes. Client-side Inertia navigation keeps the same tags in sync
via `CmsPage.tsx`'s `<Head>`; both sides key every tag with matching
`data-inertia`/`head-key` attributes so hydration reconciles instead of
duplicating.

**Fallback semantics, verified in `head.blade.php` and `SsrTest.php`:**
- SSR flag off, or on but the gateway didn't actually render this request —
  this view renders the full fallback tag set itself.
- SSR flag on and the gateway responded (`SsrState::dispatch() !== null`) —
  this view renders nothing; the SSR-rendered `<Head>` (same client code
  `CmsPage.tsx` uses) already covers it. The request-attribute check runs
  first, so the non-SSR path never even touches `SsrState`.

The head is never silently empty — a broken SSR process falls back to the
blade tags, it does not produce a page with no `<head>`.

GTM needs no wiring at all: `InjectGtm` (already in `frontendRoutes()`'s
middleware stack) inserts both halves of Google's snippet into any full-page
HTML frontend response once a container id is set in SEO settings — see
seo.md. Never touches the admin panel or non-HTML responses (Inertia/XHR/JSON,
the sitemap, robots.txt).

## Server-side rendering

Off by default; **cms-scoped**, not Inertia's own global flag. The flag is
`tbtop-cms.ssr.enabled` (config, default `false`, env `TBTOP_CMS_SSR`) —
`CmsServiceProvider::forceSsrOffByDefault()` forces `inertia.ssr.enabled` to
mirror it at boot **unconditionally**, even over a host-published
`config/inertia.php` (its vendor stub defaults `enabled` to `true` — a host
publishing it for an unrelated tweak must not silently route admin through
SSR too). `EnableSsr` middleware (already in `frontendRoutes()`) then raises
the effective flag only for the current cms frontend request via a request
attribute, read by `HttpGateway`'s disable callback
(`CmsServiceProvider::gateSsrByRequest()`) — the raised value never leaks to
admin or other host requests, even under long-lived workers. **Do not flip
`inertia.ssr.enabled` globally** — the admin client is not built or verified
for SSR. A host that genuinely needs global SSR sets
`config(['inertia.ssr.enabled' => true])` in its own provider's `boot()`
(application providers boot after package providers, so that wins) — at its
own risk, outside this package's contract.

### Host recipe

1. A cms-only SSR entry (`resources/js/ssr.tsx`) — never import
   `@tbtop/inertia-admin` here, or the (large, SSR-unverified) admin client
   ships into the SSR bundle. Standard Inertia SSR shape: `createServer()` +
   `createInertiaApp({ page, render: ReactDOMServer.renderToString, resolve,
   setup: ({ App, props }) => <App {...props} /> })`, `resolve` globbing
   `./pages/**/*.tsx`.
2. Build both bundles: `laravel({ ..., ssr: 'resources/js/ssr.tsx' })` in
   `vite.config.ts`, and `"build": "vite build && vite build --ssr"` in
   `package.json` — `vite build --ssr` writes `bootstrap/ssr/ssr.js`, the
   default target `php artisan inertia:start-ssr` looks for.
3. Turn the flag on: `'ssr' => ['enabled' => true]` in `config/tbtop-cms.php`
   (config, not `.env`, if the host's config file is a published static
   array).
4. Run `php artisan inertia:start-ssr` under a supervisor in production
   (systemd unit, Supervisor program) so it restarts on crash/deploy.
5. Dev mode: inertia-laravel v3.1 dispatches SSR to the running Vite dev
   server (`/__inertia_ssr`) instead of the built node process — served by
   the official `@inertiajs/vite` plugin (`bun add -d @inertiajs/vite`; add
   `inertia({ ssr: 'resources/js/ssr.tsx' })` to the plugins array). Without
   it, dev silently falls back to client rendering — nothing breaks, but dev
   no longer shows what SSR actually serves in production. If the package (or
   `@json-render/*`) resolves from outside the host's own `node_modules`
   (composer vendor dir, symlinked packages), dev SSR can externalize it to
   Node's own resolution and load a second React copy ("Cannot read
   properties of null" hook crashes) — force it through Vite's pipeline:
   `ssr: { noExternal: ['@json-render/react', '@json-render/core'] }`.

### Fallback semantics

- Flag off: identical to the blade-only head — no SSR gateway call at all.
- Flag on, gateway reachable: full page (head + body) from SSR.
- Flag on, gateway down/erroring: falls back to blade head + client-rendered
  body, same as flag off — a broken SSR process is never a missing `<head>`.

autofocus is the SSR reference host among those surveyed: single `app.tsx`
entry whose `setup({ el, App, props })` returns `<App {...props} />` when
`el` is absent (no DOM container under SSR) instead of calling
`createRoot(el).render(...)`.

## `public/robots.txt` must not exist

Static files under `public/` are served before any Laravel route runs, so a
scaffold-default `public/robots.txt` permanently shadows the package's
dynamic `tbtop-cms.robots` route — the SEO settings page's indexing toggle
and sitemap reference then silently do nothing.

```bash
rm public/robots.txt
```

## Admin access and roles

Access is binary, owned by this package: a user has it if a
`tbtop_user_profiles` row exists for them (`Tbtop\Cms\Admin\Models\CmsUserProfile`,
table `tbtop_user_profiles` — renamed from `cms_user_profiles` by migration
`2026_01_01_000000_update_tables_add_tbtop_prefix_table`), or if the host's
user model implements `Tbtop\Cms\Admin\Contracts\CmsAdmin` and
`canAccessCmsAdmin()` returns `true` (`CurrentAdmin::allows()` checks the
contract first, the profile row only as fallback for a plain user model).

```bash
php artisan cms:admin:create admin@example.com   # create a user and grant access
php artisan cms:admin:grant admin@example.com    # grant to an existing user
php artisan cms:admin:revoke admin@example.com   # revoke
```

**The first admin becomes the owner** (`is_owner` on the profile row). The
owner cannot be revoked or edited by other admins — a protection against
losing all access, not an extra section right. `DefaultOwnerAndLanguageCheck`
treats zero owners as an unrecoverable error state (DB surgery is the only
fix left) — same severity as zero active default languages.

**What an admin may do is the host's decision**, gated by
`tbtop-cms.roles`:
- `false` (default) — every `tbtop-cms.*` ability is unanswered by design, so
  every section is open to every admin user, including form submissions
  (lead PII), template editing, and global settings.
- `true` — every admin page's declared ability must resolve through the
  host's own `Gate`/permission system, or that section denies access. The
  full ability list a host must answer is `Tbtop\Cms\Admin\Support\CmsAbilities::ALL`
  — nine values: `tbtop-cms.pages`, `.media`, `.submissions`, `.redirects`,
  `.settings`, `.design`, `.languages`, `.users`, `.audit`. Dashboard and
  profile pages declare no ability and stay open under either setting.

`tbtop-cms.admin` config also controls: `prefix` (default `admin`), `guard`
(default `web`), `model` (host user model class, default `App\Models\User`),
and `pages` (an array of admin-page-slug => page class, overridable per-key
to swap in a host's own page implementation or `null` out a built-in page).

## Symptom → cause

| Symptom | Cause |
|---|---|
| Blank admin page | Missing or deleted `resources/views/admin.blade.php` (`admin:install`), or panel not using `->rootView('admin')` |
| Unstyled admin | Missing `admin.tsx` in Vite `input`, or missing `@source` lines in `resources/css/admin.css` |
| `Unable to locate file in Vite manifest: resources/js/pages/Cms/Page.tsx` | `resources/js/pages/Cms/Page.tsx` doesn't exist, or `app.tsx`'s `resolve` doesn't special-case `Cms/Page` |
| Placeholder boxes for CMS blocks in the admin page editor | Missing `import '@cms/admin/blocks'` side-effect import in the admin entry |
| `[MISSING_EXPORT] "home" is not exported by "resources/js/routes/index.ts"` | Starter-kit `home`-named route deleted outright instead of kept (see the `welcome`/`home` trap above) |
| Host's own routes (`/about`, etc.) 404; `/` 500s | `Cms::frontendRoutes()` registered before the host's own routes, or before a seeded home page exists |
| `/admin` shows the host's own login page, not the CMS's | Host already owns a `login`-named route (Fortify/Breeze/Jetstream/starter kit) — expected, cosmetic-only; see the login-route rule above |
| CMS pages have no `<head>` tags | `@include('tbtop-cms::head')` missing from the root view, or placed after `@inertiaHead`/`<x-inertia::head>` |
| `/robots.txt` ignores the SEO indexing toggle | `public/robots.txt` still present, shadowing the dynamic route |
| `cms:doctor` reports missing tables/routes/content right after install | `cms:install` was skipped or run with `--skip-migrations` |

Run `php artisan cms:doctor` after any wiring change instead of re-deriving
these by hand. Its checks (`src/Health/Checks/*`) span more ground than this
table: activity-log table shape, a production-safe `APP_URL`, an owner and
active default language, the root-view head include and its position, the
admin-blocks/admin-page/Tailwind-`@source` heuristics above (`JsWiringCheck`
— file-based, never escalates past a warning), a media-disk write round-trip,
mail/Telegram settings completeness, `tbtop/admin` vs `@tbtop/inertia-admin`
version drift, `robots.txt` absence, both route names above, scheduler
liveness, a `cookie`/`array` session driver (drops large page-editor
flash payloads past ~4KB silently), and whether the host's `AGENTS.md`/
`CLAUDE.md` points an agent at this guide (`AgentDocsPointerCheck` — also
never escalates past a warning).
