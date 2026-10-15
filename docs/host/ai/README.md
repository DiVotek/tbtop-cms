# Building a site on tbtop/cms — host-builder guide

> Reference for an agent working inside a **host** Laravel app that has
> `tbtop/cms` installed. Every file here is lookup-shaped and verified against
> `src/`; read the one that matches the task, not all of them. Package
> contributors use the repo's `CLAUDE.md` instead.

Put this line in the host's `AGENTS.md` / `CLAUDE.md` so the guide is found:

```
Building on tbtop/cms → read vendor/tbtop/cms/docs/host/ai/README.md first.
```

## What you are building on

A page is a list of **blocks**. Each block crosses three boundaries, and
those boundaries are the whole architecture:

| Boundary | Owns | Lives in |
|---|---|---|
| **PHP** (`Tbtop\Cms\Block\Block`, page types, seeders, Laravel) | the editor form, turning untrusted editor `data` into typed props (`resolve()`), hierarchy rules, persistence, validation, security | host `app/Cms/**`, `config/tbtop-cms.php` |
| **Wire contract** (json-render) | stored **envelope** `{id, kind, data, sort, active}` → **resolved block** `{id, kind, props}` → **page spec** `{root, elements}`; the client validates `props` against a zod **catalog** | package `src/Block/*`, `src/Frontend/Render/*`; host `resources/js/cms/catalog.ts` |
| **React** (host) | rendering: a **registry** mapping `kind` → component, the host-owned `CmsPage.tsx`, site chrome around it | host `resources/js/cms/**` |

The admin panel is `tbtop/admin`: block forms use its `S` field DSL, admin
pages its `Page` class. Its own guide is at `vendor/tbtop/admin/docs/ai/`
(`fields.md` for every field type) — this guide never restates it. Its PHP
source is under `vendor/tbtop/admin/packages/php/src/`.

## Where does my code go

Modelling pages, writing seeders or importing a site? Read
[placement.md](./placement.md) first: an ordered rule for where each piece of
a page's content goes — standard field, type field, computed value, type
frame, block, layout slot.

| You're adding… | It goes in… | Read |
|---|---|---|
| A block | **all three**: PHP class + zod entry in `catalog.ts` + component in `registry.tsx`, same change; two of three renders nothing, silently | [blocks.md](./blocks.md) |
| A page's text, picture, typed data or fixed parts | the standard page fields first, then type fields, `resolveFields()`, a host frame by `page.subtype` — blocks only for optional sections | [placement.md](./placement.md) |
| A section that holds pages other than `category`/`article` | a host page type extending `BasePageType` (`CategoryType` is not configurable) | [pages.md](./pages.md) |
| Site content | idempotent seeders per section, keyed by slug — never admin clicks | [pages.md](./pages.md#seeding-content) |
| Header / footer | layout blocks in the two slots, rendered by the host's `SiteChrome` from `cms.layout` | [wiring.md](./wiring.md), [recipes.md](./recipes.md) |
| Meta tags, canonical, sitemap, robots, JSON-LD | already shipped — edit page/site SEO settings; only block-derived JSON-LD needs host code | [seo.md](./seo.md) |
| A link an author picks (page, URL, email, phone) with its own text | `$s->link()` in the block form; read it back with `$ctx->link()` — store the value object, never a resolved href; `label` is per-locale and falls back to the page name | [blocks.md](./blocks.md) |
| A form that sends somewhere | `HandlesFormSubmission` on the block; admin fan-out (mail/telegram/bell) is automatic | [blocks.md](./blocks.md#form-handling-blocks), [notifications.md](./notifications.md) |
| A new notification type or channel | `Notifications::type()` / `::channel()` from a provider's `boot()` | [notifications.md](./notifications.md) |
| Host wiring (routes, Vite entries, `<head>`, SSR, admin access) | the host's own files — `cms:install` prints the list, `cms:doctor` checks it | [wiring.md](./wiring.md) |
| Something the CMS "doesn't have" | check [recipes.md](./recipes.md) first — most compositions exist; the rest is listed as verified absent | [recipes.md](./recipes.md) |
| A config key, command, route, prop, event, Settings key, table | look it up, don't guess | [inventory.md](./inventory.md) |

If the table can't place it, ask.

## The files

1. **[placement.md](./placement.md)** — where each piece of page content goes (first match wins), wrong placements seen in imports, the fill-count check after modelling.
2. **[wiring.md](./wiring.md)** — installing and connecting a host: route order, the two Vite entries, why the host owns `CmsPage.tsx`, shared props, `<head>`/SSR fallbacks, admin access, symptom → cause.
3. **[blocks.md](./blocks.md)** — the three-layer rule, `Block` API, `ResolveContext`, envelope → spec data flow, locale flattening, form-handling blocks, the client catalog/registry.
4. **[pages.md](./pages.md)** — `Page` columns, status vs. live, page types and hierarchy rules, slugs/URLs/redirects, source-backed pages, revisions, layout slots, seeding, cache invalidation, events.
5. **[seo.md](./seo.md)** — what the `seo` prop contains and where each value is edited, structured data, sitemap, robots, GTM, the audit/checklist, 404 log.
6. **[notifications.md](./notifications.md)** — submission lifecycle, the type/channel registry, built-in channels and their Settings keys, templates, where to register.
7. **[recipes.md](./recipes.md)** — compositions real hosts built from existing parts, and what is verified absent with the least-bad workaround.
8. **[inventory.md](./inventory.md)** — every config key, command, route, Inertia prop, contract, event, Settings key, ability, table, facade method.

## Ground rules

- **Three layers, one change.** A block's PHP `resolve()` shape and its zod schema are kept in sync by hand; the renderer validates and drops mismatches with only a console warning.
- **The host owns `CmsPage.tsx`.** Copy the package's `resources/js/cms/CmsPage.tsx` the moment you need chrome, a provider, or an extra `<head>` tag; the vendor file has no slots. Keep the SEO tags by rendering `<CmsHead seo={seo} />` from `@cms/CmsHead`, not by copying the `<Head>` block.
- **`Cms::frontendRoutes()` is the last line of `routes/web.php`.** It ends in a catch-all.
- **Content is seeded, not clicked.** Seeders are idempotent by slug and split per section.
- **Standard page fields first.** A page's description is `body`, its picture `image`; a type field or block holds only what they cannot ([placement.md](./placement.md)).
- **Vendor is read-only.** A bug in `vendor/tbtop/cms` is reported, not patched in place; a missing hook is a finding to flag, not something to work around silently.
- **`cms:doctor` after any wiring change**, instead of re-deriving from this guide.

## Vocabulary

Used consistently across all files:

- **block** — a typed content unit: PHP class with `kind()`, `form()`, `resolve()`.
- **kind** — the string that ties the three layers together.
- **envelope** — the stored JSON per block: `{id, kind, data, sort, active}`.
- **resolved block** — `{id, kind, props}`, the output of `resolve()`.
- **page spec** — the json-render tree `{root, elements}` the client renders.
- **catalog** / **registry** — the host's zod schemas / React components, keyed by kind (distinct from the PHP `BlockRegistry`/`PageTypeRegistry`/`NotificationRegistry` singletons).
- **page type** — a registered key governing hierarchy rules and (optionally) a source.
- **source-backed** — a page type whose pages are created by an external domain object and render a template when `content` is null.
- **slot** — a layout region; exactly `header` and `footer`.
- **live** — published *and* inside its schedule window; the only state served to a visitor without preview access.
- **content locale** — the admin-side locale being edited; translatable fields are stored as `{locale: value}` maps.
- **section ability** — a `tbtop-cms.*` gate name an admin page declares; enforced only when `tbtop-cms.roles` is on.
- **preview access** — admin session or signed link; the only way to see a non-live page.
- **content version** — the single cache counter every content write bumps.
