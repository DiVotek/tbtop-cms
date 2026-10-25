# Golfstrimfish — tbtop CMS dogfood site

Modernized business-card site for **ПВТП «Гольфстрім»** (fish & seafood catalog, no checkout). Built on TanStack Start + `@tbtop/cms` + Better Auth, matching the Node workbench wiring pattern.

## Prerequisites

From the repo root, fetch the admin package once (same as `packages/node`):

```bash
cd packages/node
bash scripts/fetch-admin.sh
```

## Setup

```bash
cd sites/golfstrimfish
bun run setup    # pack @tbtop/cms + @tbtop/admin into .packs, then bun install
```

Re-run `bun run setup` after changes to `packages/node`.

## Local development (SQLite)

```bash
cd sites/golfstrimfish
CMS_URL=http://localhost:3300 BETTER_AUTH_URL=http://localhost:3300 bun run dev
```

Open http://localhost:3300 — admin at `/admin` (seeded `admin@admin.com` / `password`).

The first request creates `golfstrim.sqlite`, migrates, seeds content from `src/fixture/golfstrim.ts`, and creates error pages.

Environment variables:

| Variable | Default | Purpose |
|----------|---------|---------|
| `CMS_DB` | `golfstrim.sqlite` | SQLite database file |
| `CMS_URL` | `http://localhost:3300` | Public site URL (SEO canonical, form actions) |
| `BETTER_AUTH_URL` | same as dev | Better Auth base URL |
| `CMS_FORMS_THROTTLE` | on | Set `off` for E2E |
| `CMS_MAILBOX` | `mailbox.jsonl` | Contact form “mail” sink (no real SMTP) |

## Production preview

```bash
bun run build
CMS_URL=http://localhost:3300 vite preview --port 3300
```

## E2E smoke tests

```bash
bun run e2e
```

## Cloudflare (later)

`wrangler.toml` is a stub for Workers + D1. The host would swap `better-sqlite3` for the D1 Kysely dialect and bind `CMS_URL` to the worker URL. Not deployed in this exercise.

## Content model

- **Pages:** home, production, cooperation, contacts; **category** tree under `/catalog` with **product** subtypes (`article` + `product` page type fields: price, unit).
- **Blocks:** layout header/footer, hero, category cards, product grid, product detail, features, process steps, store list, contact form (shared block).
- **CMS features used:** settings, languages, layout, shared blocks, redirects, SEO + JSON-LD, sitemap/robots, forms + notifications (mailbox), admin panel, media path hook.

See [CMS_FINDINGS.md](./CMS_FINDINGS.md) for gaps discovered while building this site.
