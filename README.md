# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel.

> Greenfield package. Core CMS domains (languages, pages, redirects, blocks,
> layouts, settings, forms, notifications, SEO) are implemented; not yet tagged
> or published to Packagist.

## Requirements

- PHP `^8.4`
- Laravel 11, 12, or 13

## Installation

This package supplies the CMS domain (content, migrations, admin pages,
frontend routes); the admin panel itself comes from its dependency
`tbtop/admin` and is wired by a separate installer. Run both, in order:

```bash
composer require tbtop/cms
php artisan vendor:publish --tag="tbtop-cms-config"
php artisan admin:install
php artisan cms:install
npm install @tbtop/inertia-admin @json-render/core @json-render/react
```

Then finish the host-side wiring both installers print but can't do for you —
each step edits a file this package does not own:

1. Add `resources/js/admin.tsx` to the Vite `input` list.
2. Register the frontend routes **last** in `routes/web.php`:
   `Cms::frontendRoutes();` (it ends in a catch-all).
3. Create `resources/js/pages/Cms/Page.tsx` (re-export `@cms/CmsPage`, or your
   own copy once you need site chrome — rendering through `@cms/CmsRenderer`,
   so a block component that throws renders nothing instead of breaking the page).
4. Add the `@cms` alias to `vite.config.ts` and `tsconfig.json`.
5. Import `@cms/admin/blocks` in `admin.tsx` and add the two `@source` lines to
   `resources/css/admin.css`.
6. Add `@include('tbtop-cms::head')` above the Inertia head in the root view.
7. Delete `public/robots.txt`.

Then create the first admin and verify:

```bash
php artisan cms:admin:create you@example.com
php artisan cms:doctor
```

Every step above — what it does, what breaks when it is missing, SSR, the
`home`/`login` route traps, admin access and roles — is covered in
[`docs/host/ai/wiring.md`](docs/host/ai/wiring.md). `cms:doctor` re-checks
the wiring after any later change.

## Building a site on it

[`docs/host/ai/`](docs/host/ai/README.md) is the reference for building a
host site: blocks (PHP + zod + React), page types and hierarchy, seeding
content, SEO, forms and notifications, recipes for what the package does not
ship, and a full inventory of config keys, commands, routes, props and events.
It is written for an AI agent working in the host repo and verified against
`src/`. Add this line to the host's `AGENTS.md` / `CLAUDE.md`:

```
Building on tbtop/cms → read vendor/tbtop/cms/docs/host/ai/README.md first.
```

## Admin access and roles

Access to the admin panel is binary: a user has it if a `tbtop_user_profiles`
row exists for them (`cms:admin:create` / `cms:admin:grant` / `cms:admin:revoke`,
or the Users page), or if the host's user model implements
`Tbtop\Cms\Admin\Contracts\CmsAdmin`. The first admin becomes the owner and
cannot be revoked by other admins.

What an admin may then do is the host's decision. With `tbtop-cms.roles`
off (default) every section is open to every admin; with it on, each section
requires its `tbtop-cms.*` ability (`Tbtop\Cms\Admin\Support\CmsAbilities::ALL`)
to be answered by the host's own `Gate`. Details in
[`docs/host/ai/wiring.md`](docs/host/ai/wiring.md#admin-access-and-roles).

## Development

```bash
composer install
composer test        # Pest test suite (Orchestra Testbench)
composer lint        # Laravel Pint
composer analyse     # PHPStan / Larastan
composer serve       # Boot the Workbench host app for manual review
```

See `CLAUDE.md` for the full command reference and testing policy.
Upgrade notes live in [`docs/UPGRADING.md`](docs/UPGRADING.md).

## Versioning and releases

This package follows [semver](https://semver.org/), with the standard pre-1.0
caveat: while the version stays `0.x`, a minor release may contain breaking
changes. Once tagged `1.0.0`, breaking changes only ship in a major bump.
Changes are documented in `CHANGELOG.md`.

Each `tbtop/cms` release declares the range of `tbtop/admin` it supports in
`composer.json` (`require.tbtop/admin`) — that constraint is the source of
truth for compatibility, not this README.

## Contributing and security

Bug reports, feature requests, and pull requests are welcome — see
[CONTRIBUTING.md](CONTRIBUTING.md) for dev setup, the checks a change needs to
pass, and PR guidelines. Participation in this project is governed by the
[Code of Conduct](CODE_OF_CONDUCT.md).

To report a security vulnerability, see [SECURITY.md](SECURITY.md) — please
do not open a public issue.

## License

MIT. See `LICENSE.md`.
