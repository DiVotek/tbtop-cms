# Contributing to tbtop/cms

Thanks for your interest in contributing. This document covers dev setup, the
checks your change needs to pass, and how PRs are reviewed.

For substantial changes — new admin sections, schema changes, anything that
changes a public contract — please open a GitHub issue first to discuss the
approach before writing code.

## Dev setup

```bash
git clone git@github.com:DiVotek/tbtop-cms.git
cd tbtop-cms
composer install
```

`composer install` also discovers the package against the bundled Testbench
workbench (via `post-autoload-dump`), so the test suite and the manual-review
app are ready to go right after.

If you're touching the admin-side frontend (`resources/js`), also install the
JS dependencies:

```bash
bun install
```

## Running the checks

```bash
composer test          # Pest test suite (Orchestra Testbench)
composer lint           # Laravel Pint — fixes style issues
composer test:lint      # Laravel Pint — check only, no changes
composer analyse        # PHPStan / Larastan (level 6)
composer refactor        # Rector — apply refactorings
composer test:refactor   # Rector — dry run
```

`:changed` variants (`composer test:all:changed`, `test:lint:changed`,
`analyse:changed`, `test:refactor:changed`, `test:changed`) run the same tools
against only the PHP files changed versus `HEAD` — useful while iterating.

Frontend checks, from `package.json`:

```bash
npx tsc --noEmit      # TypeScript check (or: bun run typecheck)
bun test resources    # JS/TS tests (or: bun run test)
```

Bun's test runner can hit an `EMFILE` (too many open files) error on some
macOS setups when the suite is large. If you see this locally, raising the
open-file limit (`ulimit -n`) for the shell usually resolves it; either way,
CI is authoritative for frontend tests.

CI (`.github/workflows/ci.yml`) runs the check-only variants of these:
`composer validate --strict`, Pint in check mode (`test:lint`), PHPStan
(`analyse`), the Pest suite on PHP 8.4 / Testbench 11, and the frontend type
check and tests. Each job runs only when its files change (see the `Path
filter` job); the `CI ok` check sums them up and must pass before merge.
Rector is a local aid and is not enforced in CI.

## Manual review (Workbench)

The package includes an Orchestra Testbench workbench app for booting a real
Laravel app against your working copy:

```bash
composer serve   # builds the workbench and starts `testbench serve`
```

Use this to click through admin pages and frontend rendering for changes that
are hard to fully cover with automated tests (layout, styling, interactive
admin blocks).

## Pull request guidelines

- Branch from `main`.
- Keep commit subjects in the Conventional Commits style used in the project
  history: `type(scope): subject` (e.g. `fix(seo): exclude pages with a
  non-self canonical from the sitemap`). Common types include `feat`, `fix`,
  `chore`, `refactor`, `docs`, `test`.
- Keep PRs scoped to one concern. Land unrelated fixes as separate PRs.
- Add or adjust tests for any behavior change — see the testing policy below.
- All CI jobs (lint, analyse, test matrix, frontend) must be green.
- Resolve every review conversation thread before merge. An automated
  reviewer (`triage`) comments on every PR; reply to or fix each finding.
- Maintainers merge with a squash, so the PR title becomes the commit subject.

### Testing policy

- Boundary/integration tests by default: exercise the package through its
  public surface (service provider, routes, commands, models) with real
  wiring, not mocks.
- Unit tests are for dense logic, security-sensitive code, or rules that are
  hard to reach from the boundary.
- Test behavior and outcomes, not private implementation shape — a
  behavior-preserving refactor should not break a test.
- A bug fix should start with a failing regression test that reproduces it.

### Translations

User-facing admin strings live under `resources/lang/{en,uk}`. Any change that
adds, removes, or edits a string must update **both** locales in the same PR —
a string present in only one locale is treated as a gap, not an acceptable
partial state.

## Code style

PHP style is enforced by Laravel Pint (`composer test:lint` in CI); static
analysis by PHPStan/Larastan at level 6 (`composer analyse`). Run `composer
lint` before committing to auto-fix style issues.
