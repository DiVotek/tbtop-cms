# Lumen Studio (dogfood site)

Indie studio landing + journal built on `@tbtop/cms` (Node port), TanStack Start, Better Auth, and SQLite locally. Content is seeded through the CMS fixture API and editable in `/admin`.

## Prerequisites

- [Bun](https://bun.sh) 1.1+
- Pack `@tbtop/admin` once from the monorepo: `../../packages/node/scripts/fetch-admin.sh`

## Setup

```bash
cd sites/studio
bash scripts/setup.sh    # pack @tbtop/cms + @tbtop/admin into .packs/
bun install
```

Re-run `bash scripts/setup.sh && bun update @tbtop/cms @tbtop/admin` after changes to `packages/node`.

## Development

```bash
bun run dev
```

Open http://localhost:3300 (frontend) and http://localhost:3300/admin/pages (CMS admin).

Seeded users (Better Auth):

- `admin@admin.com` / `password` (admin)
- `editor@editor.com` / `password` (editor)

Environment (optional):

| Variable | Default | Purpose |
|----------|---------|---------|
| `CMS_DB` | `studio.sqlite` | SQLite database file |
| `CMS_URL` | `http://localhost:3300` | Public site URL (SEO, forms, canonical) |
| `BETTER_AUTH_URL` | same as dev origin | Better Auth base URL |
| `CMS_MAILBOX` | `mailbox.jsonl` | Local “mail” sink for form notifications |
| `CMS_FORMS_THROTTLE` | on | Set to `off` for repeated form tests |

First request runs `migrateCms`, Better Auth migrations, fixture seed, error pages, and a demo slug change (`/contact` → `/get-in-touch` auto-redirect).

## Production preview

```bash
bun run build
CMS_URL=http://localhost:3300 BETTER_AUTH_URL=http://localhost:3300 bun run start
```

## Tests

```bash
bun run e2e
```

Playwright builds the app, serves `vite preview` on port **3310** with a fresh `e2e.sqlite`, and runs smoke tests (home, journal, locales, forms, redirects, SEO, RSS).

## Cloudflare Workers + D1 (later)

`wrangler.toml` sketches a D1 binding and `nodejs_compat`. The host would swap `better-sqlite3` for the D1 Kysely dialect, set `CMS_URL` to the worker URL, and run the same CMS bootstrap — not wired in this dogfood app yet.

## CMS features exercised

- Locales **en** + **de**, layout header/footer shared blocks, custom blocks (`StudioHero`, `Newsletter`, …)
- Page tree: home, about, work, contact, **category** journal with **article** posts (`journal` subtype + tags)
- SEO props, JSON-LD, `robots.txt`, sitemap, manual + auto redirects, scheduled/draft posts
- Contact + newsletter forms, notifications to `mailbox.jsonl`, admin inbox
- Revisions config, custom admin page (`Studio overview`), media disk at `/storage`

See [CMS_FINDINGS.md](./CMS_FINDINGS.md) for gaps discovered while building this site.
