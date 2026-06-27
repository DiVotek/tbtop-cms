# tbtop/cms

Minimal `tbtop` + `json-render` CMS core for Laravel. Greenfield package; the
archived `divotek/kit` is a reference only.

Composer: `tbtop/cms` · Namespace: `Tbtop\Cms`
PHP ^8.3 · Laravel 11 / 12 · No Filament.

## Stack

- Package tools: `spatie/laravel-package-tools`
- Tests: Pest (arch + laravel plugins) on Orchestra Testbench; manual review via Workbench
- Lint: Laravel Pint (laravel preset)
- Static analysis: PHPStan / Larastan (level 6)
- Refactor: Rector
- CI: GitHub Actions — `ci.yml` (lint -> analyse -> test)

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

## Testing policy

- Boundary / integration tests by default: drive the package through its public
  surface (service provider, routes, commands, models) with real wiring.
- Unit tests only for dense logic, security-sensitive code, or rules hard to
  reach from the boundary.
- Test behavior and outcomes, not private implementation shape. A
  behavior-preserving refactor must not break a test.
- A bug fix starts with a failing regression test that reproduces it.

## Structure

```
src/        # Package code (Tbtop\Cms)
config/     # tbtop-cms.php published config
tests/      # Pest tests (boundary by default)
workbench/  # Testbench host app for manual review
```
