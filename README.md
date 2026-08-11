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

## Admin access and roles

Access to the admin panel is binary and owned by this package: a user has it if
a `cms_user_profiles` row exists for them, or if the host's user model
implements `Tbtop\Cms\Admin\Contracts\CmsAdmin` and returns `true`. Grant and
revoke it from the Users page, or from the CLI:

```bash
php artisan cms:admin:create admin@example.com   # create a user and grant access
php artisan cms:admin:grant admin@example.com    # grant to an existing user
php artisan cms:admin:revoke admin@example.com   # revoke
```

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
