# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel. Greenfield package; the
archived `divotek/kit` is a reference only.

Composer: `tbtop/cms` · Namespace: `Tbtop\Cms`
PHP ^8.4 · Laravel 11 / 12 / 13 · No Filament.

## Stack

- Package tools: `spatie/laravel-package-tools`
- Tests: Pest (arch + laravel plugins) on Orchestra Testbench; manual review via Workbench
- Lint: Laravel Pint (laravel preset)
- Static analysis: PHPStan / Larastan (level 6)
- Refactor: Rector
- CI: GitHub Actions — `ci.yml` runs lint, analyse, blocks-types
  (`composer types:blocks:check`), test and frontend (bun) as parallel jobs

## Commands

```bash
composer test        # Pest
composer lint        # Pint (fix)
composer test:lint   # Pint (check only)
composer analyse     # PHPStan
composer refactor    # Rector (apply)
composer serve       # Boot the Workbench app for manual review
```

`:changed` variants (`composer test:all:changed`, `lint:changed`, `analyse:changed`, …)
run the same tools against git-changed PHP files — prefer them during development.

Headless checks: `vendor/bin/testbench route:list`, `vendor/bin/testbench tinker
--execute=…`, or a one-off Pest test on the existing TestCase.

Workbench quirks:

- `composer serve` needs `CACHE_STORE=array`: the seeder bumps the content-version
  cache and the database cache store has no `cache` table in the fresh sqlite.
- `composer serve` syncs `workbench/public/build` into the served `public/` at
  build time. Run `bun run build` first, or the admin 500s on a missing Vite
  manifest; if the server was already up, symlink `workbench/public/build` to
  `vendor/orchestra/testbench-core/laravel/public/build`.
- Every `composer install/update/dump-autoload` runs `package:purge-skeleton`
  (`post-autoload-dump`): the skeleton DB and `public/build` are gone and the
  admin 500s on `no such table`. Rebuild with `bun run build` and
  `CACHE_STORE=array composer build`, then restart `serve`. App log:
  `vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log`.
- DB: `vendor/orchestra/testbench-core/laravel/database/database.sqlite`; every
  package table is prefixed `tbtop_`. Log in via `/_workbench/login/1`
  (seeded admin@admin.com / password). `AutoLoginAdmin` overrides
  `/_workbench/login/{id}` for any other id: to act as another user, rename
  admin@admin.com in the DB first. The seeded admin is not the owner.
- Workbench has no SSR, so JSON-LD and props are not in the HTML body: read them
  from the JSON in `<script data-page>` of the GET response.
- The tbtop demo app often holds the usual ports. Check
  `lsof -iTCP:<port>` and pass another `--port` rather than stopping a server
  you did not start.

## Testing policy

- Boundary / integration tests by default: drive the package through its public
  surface (service provider, routes, commands, models) with real wiring.
- Unit tests only for dense logic, security-sensitive code, or rules hard to
  reach from the boundary.
- Test behavior and outcomes, not private implementation shape. A
  behavior-preserving refactor must not break a test.
- A bug fix starts with a failing regression test that reproduces it.
- Tests boot the workbench (`WithWorkbench`); its `WorkbenchServiceProvider`
  registers the Hero, ContactForm and Faq block kinds every test sees.

## Structure

```
src/        # Package code (Tbtop\Cms)
config/     # tbtop-cms.php published config
tests/      # Pest tests (boundary by default)
workbench/  # Testbench host app for manual review
docs/host/ai/  # Host-builder guide for agents; docs/ stays for package-internal notes
```

A new migration needs a `YYYY_MM_DD_NNNNNN_` filename in `database/migrations/`
and a `->hasMigration()` line in `CmsServiceProvider`: the migrator runs files
in filename order, and only listed files are loaded.

## tbtop/admin

The admin chrome (header, notifications, tabs, fields, slide-overs) and the PHP
DSL (`S`, `Page`, `Column`, actions) are not in this repo. They come from the
monorepo https://github.com/DiVotek/tbtop: `packages/php` is composer
`tbtop/admin`, `packages/client` is npm `@tbtop/inertia-admin`. Admin UI bugs
are fixed there — check its open PRs for the same fix first.

Read the installed version in vendor: its agent guide is
`vendor/tbtop/admin/docs/ai/`, its PHP source
`vendor/tbtop/admin/packages/php/src` (there is no `vendor/tbtop/admin/src`).

Bump `tbtop/admin` in `composer.json` and `@tbtop/inertia-admin` in
`package.json` together. `bun.lock` is committed, `composer.lock` is not.
`bun update` rewrites the `package.json` range, so set the range by hand.

## PRs

Merging to `main` needs the Lint, Analyse, Test and Frontend checks green and
every review thread resolved. Auto-merge is off: merge with
`gh pr merge --squash`. Auto-review is `pr-agent-pilot[bot]`: its verdict is
the `triage` check-run, its findings are inline review comments. A clean re-run
posts no new review, so poll the check-run, not reviews. Validate each finding,
fix or reply, then resolve the thread (GraphQL `resolveReviewThread`). The
`Devin Review` check passes without reviewing (no credits left).

## Docs

`docs/host/ai/` is verified against `src/`. A PR that changes a host-facing
extension point — `Block`, `PageTypeInterface`, `Channel`, a config key, a
command, a route, an Inertia prop, a `Settings` key — updates the matching
file there (and `inventory.md`) in the same PR.
