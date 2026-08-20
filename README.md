# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel.

> Greenfield package. Core CMS domains (languages, pages, redirects, blocks,
> templates, settings, forms) are implemented; not yet tagged or published to
> Packagist.

## Requirements

- PHP `^8.4`
- Laravel 11, 12, or 13

## Installation

This package supplies the CMS domain (content, migrations, admin pages,
frontend routes); the admin panel itself — root view, Inertia entry,
stylesheet — comes from its dependency `tbtop/admin` and is wired by a
separate installer. Run both, in order:

```bash
composer require tbtop/cms
php artisan vendor:publish --tag="tbtop-cms-config"
php artisan admin:install
php artisan cms:install
```

- `vendor:publish --tag="tbtop-cms-config"` publishes `config/tbtop-cms.php`.
- `admin:install` (from `tbtop/admin`) publishes three files this package's
  panel depends on: `resources/views/admin.blade.php` (a dedicated root view —
  see below), `resources/js/admin.tsx` (a dedicated Inertia entry, so the
  admin bundle never ships to public visitors), and `resources/css/admin.css`.
  It only writes files that don't already exist; on a populated host, pass
  `--force` if you're intentionally overwriting one. It also prints manual
  steps this README covers below — do them in order, since each one it lists
  depends on the ones before it.
- `cms:install` runs migrations and seeds the default language and home page.
  It's the only documented way to get there: skip it and `cms:doctor` reports
  three errors (missing tables/route registration/content) instead of one.

Then finish the host-side wiring both installers print but can't do for you:

```bash
npm install @tbtop/inertia-admin
```

The README imports from this package below; nothing installs it for you.

```js
// vite.config.ts
laravel({
    input: ['resources/css/app.css', 'resources/js/app.tsx', 'resources/js/admin.tsx'],
    refresh: true,
}),
```

Add the admin entry alongside the app one. Miss this and `admin.css` never
compiles — it's silently absent from `public/build/manifest.json`, so the
panel loads unstyled with no error.

Register the frontend routes (`routes/web.php`):

```php
use Tbtop\Cms\Facades\Cms;

Cms::frontendRoutes();
```

`frontendRoutes()` ends in a catch-all (`Route::get('{path?}', ...)->where('path', '.*')`)
that resolves any path the CMS doesn't recognize into a 404 page — call it
**last**, after every route the host defines itself. Registered earlier, it
swallows everything after it: a populated host's own `/about`, `/contact`,
`/pricing` all start 404ing, and `/` 500s until the CMS has a seeded page to
resolve. On a fresh `laravel new --react` app, also delete or rename the
starter kit's own root route (`routes/web.php`: `Route::inertia('/', 'welcome')`)
— it matches `/` first and permanently shadows the CMS's seeded home page,
`frontendRoutes()` position notwithstanding.

Create the first admin user and verify the install:

```bash
php artisan cms:admin:create you@example.com
php artisan cms:doctor
```

The sections below are the detail behind each piece `admin:install` and
`cms:install` print as a manual step — read them once, then use `cms:doctor`
on future changes instead of re-reading this file.

## Host application requirements

The admin panel (`tbtop/admin`) renders through the host's own Inertia setup.
The host-side wiring below is not created by this package; skipping a piece
produces a blank page, an Inertia resolution error, unstyled admin markup, or
placeholder boxes where this package's own blocks should render.

### Root Blade view (`admin`)

`tbtop/admin`'s `PanelConfig` defaults every panel's root view to `app` — the
host's own public root view, which loads the public frontend bundle and has no
way to resolve the admin client's `admin/page` component. This package's panel
(`Tbtop\Cms\Admin\CmsPanel`) already overrides that default with
`->rootView('admin')`, because the admin client has its own Vite entry and
needs a matching root view to load it. `admin:install` (from `tbtop/admin`)
publishes that view at `resources/views/admin.blade.php`. Nothing manual is
needed here — just don't skip `admin:install`, and don't delete the published
view.

### Inertia entry and page component (`admin/page`)

Every admin page request renders the Inertia component `admin/page`.
`admin:install` publishes `resources/js/admin.tsx` — a dedicated entry, kept
separate from the host's own `resources/js/app.tsx` so the admin bundle (the
richtext editor alone is a few hundred KB) never ships to public visitors. Its
`resolve()` handles `admin/page` inline by importing `AdminPage` from
`@tbtop/inertia-admin`; there's no separate page-component file to create.

### Admin stylesheet (`@source` over this package)

The client package ships a Tailwind v4 **source** file, so the host pipeline
compiles it. `admin:install` publishes `resources/css/admin.css` with one
`@source` line already in it, scoped to the host's own `app/**/*.php` — enough
for classes the host's own code authors, but not for this package's. Add the
two lines below on top of what's published, so Tailwind also scans this
package for the class names **it** authors — PHP for the admin DSL
(`->class()`, section `class` options), TSX for the admin blocks below.
Without them those classes silently compile to nothing and the affected blocks
render unstyled:

```css
/* resources/css/admin.css */
@import '@tbtop/inertia-admin/styles.css';

@source '../../app/**/*.php';
@source '../../vendor/tbtop/cms/src/**/*.php';
@source '../../vendor/tbtop/cms/resources/js/cms/admin/**/*.tsx';
```

### Admin blocks

A few admin pages render through custom node kinds whose renderers live in this
package — the search preview measures the rendered pixel width of a title and
switches between desktop and mobile layouts, neither of which a server-rendered
display block can do. Importing the barrel registers them; without the import
the client falls back to its unknown-kind placeholder for those nodes:

```tsx
// resources/js/admin.tsx — side-effect import, before createInertiaApp()
import '@cms/admin/blocks';
```

This goes in the **admin** entry, not the host's `app.tsx` — putting it in
`app.tsx` instead ships the admin blocks (and their dependencies) to every
public visitor, defeating the bundle split `admin:install` sets up. (`app.tsx`
is still accepted as a fallback for hosts wired before `admin:install`
published a dedicated entry, but new installs should use `admin.tsx`.)

This assumes the `@cms` alias the frontend setup already uses (`vite.config.js`
`resolve.alias` plus `tsconfig.json` `paths`), pointing at
`vendor/tbtop/cms/resources/js/cms`:

```ts
// vite.config.ts
resolve: { alias: { '@cms': path.resolve(__dirname, 'vendor/tbtop/cms/resources/js/cms') } },
```

```json
// tsconfig.json
{ "compilerOptions": { "paths": { "@cms/*": ["./vendor/tbtop/cms/resources/js/cms/*"] } } }
```

### Frontend props (`ShareCmsProps`)

`Tbtop\Cms\Frontend\Http\Middleware\ShareCmsProps` shares a `cms` prop on every
frontend (non-admin) Inertia response:

- `cms.settings` — site settings for frontend consumption.
- `cms.locale` — the current request locale.
- `cms.defaultLocale` — the configured default content locale.
- `cms.languages` — active frontend languages, each with `locale`, `name`, `is_default`.
- `cms.layout` — resolved block lists (`header`/`footer`) the host renders in
  its persistent layout.

### Server-rendered `<head>` (SEO)

Frontend meta tags (title, description, canonical, Open Graph, Twitter Card,
hreflang, favicon, JSON-LD) are rendered server-side so crawlers and
social-network bots see them without executing JS — whether or not full-page
SSR (see the next section) is turned on. Add the include to the host's root
view, **above** the Inertia head output — either the `@inertiaHead` directive
or its `<x-inertia::head>` component form (a current Laravel 13 React scaffold
uses the latter; both are the same directive under the hood):

```blade
{{-- resources/views/app.blade.php --}}
@vite(['resources/css/app.css', 'resources/js/app.tsx'])
@include('tbtop-cms::head')
<x-inertia::head>
    <title>{{ config('app.name', 'Laravel') }}</title>
</x-inertia::head>
```

The included view (`resources/views/head.blade.php` in this package) reads
`$page['props']['seo']` off the Inertia root view's own `$page` array and
renders nothing when it is absent (e.g. the root view reused by a non-cms
route) — no extra wiring needed beyond the `@include` line. Client-side Inertia
navigation keeps the same tags in sync via `CmsPage.tsx`'s `<Head>`; the two
are reconciled by matching `data-inertia`/`head-key` attributes, so hydration
does not duplicate them.

With SSR enabled, the same tags come from the SSR-rendered `<Head>` instead:
the include detects this (via Inertia's own SSR dispatch) and renders nothing,
so the tags are never duplicated. If the SSR process is down, this view falls
back to rendering the tags itself — the head is never silently empty.

Google Tag Manager needs no wiring at all: when a container ID is set in the
SEO settings, the `InjectGtm` middleware inserts both halves of Google's
snippet into every full-page frontend response — the loader `<script>` right
after the opening `<head>` tag, the `<noscript>` iframe right after `<body>` —
per Google's install rules. The admin panel never gets the snippet.

### Server-side rendering

By default the cms frontend body renders client-side only: Googlebot's
second-wave render queue, non-rendering crawlers (LLM bots, smaller search
engines), and initial paint all pay for that. Turning on Inertia SSR renders
the full page (head **and** body) on the server for every frontend request.

SSR is **opt-in and cms-scoped**: the flag lives at `tbtop-cms.ssr.enabled`
(default `false`), not at Inertia's own `inertia.ssr.enabled`. This package
owns the Inertia flag: it forces `inertia.ssr.enabled` to `false` at boot —
even when the host has published `config/inertia.php`, since the vendor stub
defaults it to `true` and publishing the file for an unrelated tweak must not
silently route the admin through SSR — and only raises it for the duration of
a single cms frontend request, via a middleware
(`Tbtop\Cms\Frontend\Http\Middleware\EnableSsr`) registered on the cms
frontend route group next to `SetLanguage`; the previous value is restored
when the request unwinds, so long-lived workers never leak the raised flag
into admin requests. **Do not enable global SSR in the host** — the admin
client is not built or verified for it. A host that genuinely needs it can
set `config(['inertia.ssr.enabled' => true])` in its own service provider's
`boot()` (application providers boot after package providers, so that
assignment wins) — at its own risk.

#### Host recipe

1. An SSR entry point that resolves cms pages only — never import
   `@tbtop/inertia-admin` here, so the SSR bundle stays free of the (large,
   SSR-unverified) admin client:

   ```tsx
   // resources/js/ssr.tsx
   import { createInertiaApp, type ResolvedComponent } from '@inertiajs/react';
   import createServer from '@inertiajs/react/server';
   import ReactDOMServer from 'react-dom/server';

   createServer((page) =>
       createInertiaApp({
           page,
           render: ReactDOMServer.renderToString,
           resolve: (name) => {
               const pages = import.meta.glob<{ default: ResolvedComponent }>('./pages/**/*.tsx');

               return pages[`./pages/${name}.tsx`]().then((module) => module.default);
           },
           setup: ({ App, props }) => <App {...props} />,
       }),
   );
   ```

2. Add the SSR entry to the Vite build input and build both bundles:

   ```js
   // vite.config.js
   laravel({
       input: ['resources/css/app.css', 'resources/js/app.tsx'],
       ssr: 'resources/js/ssr.tsx',
       refresh: true,
   }),
   ```

   ```json
   // package.json
   { "scripts": { "build": "vite build && vite build --ssr" } }
   ```

   `vite build --ssr` reads `ssr.tsx` (per the `ssr` input above) and writes
   `bootstrap/ssr/ssr.js`, which is what `php artisan inertia:start-ssr`
   looks for by default.

3. Turn the flag on (config, not `.env`, if the host's `tbtop-cms.php` is a
   published static array — check how the rest of the host's config is set):

   ```php
   // config/tbtop-cms.php
   'ssr' => ['enabled' => true],
   ```

4. Start the SSR process. `php artisan inertia:start-ssr` runs it in the
   foreground; in production, run it under a supervisor (systemd unit,
   Supervisor program, etc.) so it restarts on crash and on deploy, pointing
   at `bootstrap/ssr/ssr.js`.

5. Dev mode: whenever the Vite dev server is running, inertia-laravel v3.1
   dispatches SSR to it (`/__inertia_ssr`) instead of the built node process.
   That endpoint is served by the official `@inertiajs/vite` plugin — without
   it, dev requests silently fall back to client rendering (with the blade
   head, so nothing breaks — but what you see in dev is not what SSR serves
   in production):

   ```bash
   bun add -d @inertiajs/vite
   ```

   ```js
   // vite.config.js
   import inertia from '@inertiajs/vite';
   // plugins: [laravel({...}), inertia({ ssr: 'resources/js/ssr.tsx' }), react()]
   ```

   If the cms package (and its `@json-render/*` dependencies) resolve through
   a path outside the host's own `node_modules` (composer vendor dir,
   symlinked packages), dev SSR externalizes them to Node's resolution and
   can load a second React copy ("Cannot read properties of null" hook
   crashes). Force them through the Vite pipeline so `resolve.dedupe`
   applies:

   ```js
   // vite.config.js
   ssr: { noExternal: ['@json-render/react', '@json-render/core'] },
   ```

#### Fallback semantics

- Flag off (default): behaves exactly as the blade-only head above — no SSR
  gateway call is made.
- Flag on, SSR process reachable: full page (head + body) comes from SSR.
- Flag on, SSR process down or erroring: falls back to the blade head and a
  client-rendered body, same as the flag being off — a broken SSR process
  never means a missing `<head>`.

### `public/robots.txt` must not exist

Laravel's default scaffold ships `public/robots.txt`. Static files under
`public/` are served before any route, so that file **shadows** this
package's dynamic `/robots.txt` route (`RobotsController`) — the host's
sitemap reference and indexing toggle never take effect while it's present.
Delete it:

```bash
rm public/robots.txt
```

### 404 log

Every frontend path that resolves to neither a page nor a redirect is
recorded (deduplicated per locale+path, with a hit counter) and browsable from
the "404 log" admin page. Recording is on by default; turn it off from the SEO
settings page, or narrow the noise filter it applies before writing
(`config/tbtop-cms.php`):

```php
'not_found' => [
    'ignore' => ['.php', '.env', 'wp-', '.git', '.well-known', 'xmlrpc', '.aspx', '.cgi'],
],
```

**The package schedules `cms:not-found:prune` daily on its own** — no console
kernel/routes wiring needed, as long as your server's cron runs
`php artisan schedule:run` every minute. It deletes rows whose `last_seen_at`
is older than the retention window set on the SEO settings page (default 90
days). To prune on a different cadence, or not at all, turn off the built-in
schedule and wire it yourself:

```php
'not_found' => [
    'auto_prune' => false,
],
```

```php
// routes/console.php
Schedule::command('cms:not-found:prune')->weekly();
```

## Admin access and roles

Access to the admin panel is binary and owned by this package: a user has it if
a `cms_user_profiles` row exists for them, or if the host's user model
implements `Tbtop\Cms\Admin\Contracts\CmsAdmin` and returns `true`. Users can
be created and edited, and access granted and revoked, from the Users page, or
from the CLI:

```bash
php artisan cms:admin:create admin@example.com   # create a user and grant access
php artisan cms:admin:grant admin@example.com    # grant to an existing user
php artisan cms:admin:revoke admin@example.com   # revoke
```

**The first admin becomes the owner.** The owner cannot be revoked or edited
by other admins — this is a protection, not a privilege: it grants no extra
section rights. What an admin may do is still entirely the host's business via
`tbtop-cms.roles` below.

**What an admin may then do is the host's decision.** The package ships no
roles. Each admin page declares a `tbtop-cms.*` ability (the full set is
`Tbtop\Cms\Admin\Support\CmsAbilities::ALL`), and the `tbtop-cms.roles`
config flag decides who answers it:

- `false` (default) — no ability is declared, so **every section is open to
  every user with admin access**, including form submissions (lead PII),
  template editing, and global settings. Appropriate when everyone with admin
  access is equally trusted.
- `true` — pages require their ability and your app must answer it. The package
  never defines these gates, so an ability you have not answered denies access.
  Define all eight before flipping the flag.

```php
// A host using spatie/laravel-permission: permission names match the abilities.
// Or answer them directly:
foreach ([
    'tbtop-cms.pages', 'tbtop-cms.media', 'tbtop-cms.submissions',
    'tbtop-cms.redirects', 'tbtop-cms.settings', 'tbtop-cms.design',
    'tbtop-cms.languages', 'tbtop-cms.users',
] as $ability) {
    Gate::define($ability, fn (User $user): bool => $user->isEditor());
}
```

The dashboard and profile pages declare no ability and stay open to anyone with
admin access under either setting.

## Development

```bash
composer install
composer test        # Pest test suite (Orchestra Testbench)
composer lint        # Laravel Pint
composer analyse     # PHPStan / Larastan
composer serve       # Boot the Workbench host app for manual review
```

See `CLAUDE.md` for the full command reference and testing policy.

## Versioning and releases

This package follows [semver](https://semver.org/), with the standard pre-1.0
caveat: while the version stays `0.x`, a minor release may contain breaking
changes. Once tagged `1.0.0`, breaking changes only ship in a major bump.
Changes are documented in `CHANGELOG.md`.

Each `tbtop/cms` release declares the range of `tbtop/admin` it supports in
`composer.json` (`require.tbtop/admin`) — that constraint is the source of
truth for compatibility, not this README. Supported Laravel and PHP versions
are as listed under Requirements above.

## Contributing and security

Bug reports, feature requests, and pull requests are welcome — see
[CONTRIBUTING.md](CONTRIBUTING.md) for dev setup, the checks a change needs to
pass, and PR guidelines. Participation in this project is governed by the
[Code of Conduct](CODE_OF_CONDUCT.md).

To report a security vulnerability, see [SECURITY.md](SECURITY.md) — please
do not open a public issue.

## License

MIT. See `LICENSE.md`.
