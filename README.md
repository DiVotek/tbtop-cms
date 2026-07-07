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
