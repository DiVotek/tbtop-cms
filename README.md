# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel.

> Greenfield package. Core CMS domains (languages, pages, redirects, blocks,
> templates, settings, forms) are implemented; not yet tagged or published to
> Packagist.

## Requirements

- PHP `^8.4`
- Laravel 11, 12, or 13

## Installation

```bash
composer require tbtop/cms
```

Publish the config file:

```bash
php artisan vendor:publish --tag="tbtop-cms-config"
```

## Host application requirements

The admin panel (`tbtop/admin`) renders through the host's own Inertia setup. Two
host-side files are required and are not created by this package; skipping either
produces a blank page or an Inertia resolution error.

### Root Blade view (`app`)

The admin panel renders into the host's root view, which defaults to `app` (see
`Tbtop\Admin\Panels\PanelConfig::$rootView`). The standard Laravel/Inertia
scaffold view must exist at `resources/views/app.blade.php`. This is not
configurable from `tbtop/cms` today — only a per-panel `rootView()` override on
the upstream `Panel` class can change it.

### Inertia page component (`admin/page`)

Every admin page request renders the Inertia component `admin/page`. The host's
`createInertiaApp` resolver must be able to resolve it — typically a thin
delegate to the client package's page component:

```tsx
// resources/js/pages/admin/page.tsx
import { AdminPage } from "@tbtop/inertia-admin";
export default AdminPage;
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
view, **above** `@inertiaHead`:

```blade
{{-- resources/views/app.blade.php --}}
@vite(['resources/css/app.css', 'resources/js/app.tsx'])
@include('tbtop-cms::head')
@inertiaHead
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

**The package does not prune old rows on its own.** Schedule the cleanup
command yourself, in your app's console kernel/routes:

```php
// routes/console.php
Schedule::command('cms:not-found:prune')->daily();
```

`cms:not-found:prune` deletes rows whose `last_seen_at` is older than the
retention window set on the SEO settings page (default 90 days).

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

## License

MIT. See `LICENSE.md`.
