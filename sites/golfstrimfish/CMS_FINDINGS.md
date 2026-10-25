# CMS findings — Golfstrimfish dogfood site

Building `sites/golfstrimfish` on `@tbtop/cms` (Node port, branch `claude/project-thread-15201m`) against the live [golfstrimfish.com](https://golfstrimfish.com) content model.

## Gaps and bugs

### Blocker

_None for this site scope after the documented workarounds below._

### Should-fix

1. **Fixture seed does not resolve page keys in block fields**
   - **Expected:** `seedFixtures` could accept page keys in `pagesField` / `linkField` values (like redirect `to` paths), resolving them after the page tree exists.
   - **Happened:** Storing `"products": ["p-bream-steak"]` or `link.page: "cat-cold"` in fixture block data is left as-is; `pagesField` expects numeric ids, so grids stay empty and category card links break.
   - **Repro:** Use `packages/node/src/fixtures/seed.ts` with a `pagesField` or `linkField` pointing at another fixture page's `key`.
   - **Direction (hypothesis):** Second pass in `seedFixtures` that walks block data and replaces known keys with ids from the returned map (similar to redirect `to` slug resolution).

2. **`pagesField` resolves only `{ id, name, url }` — not page-type fields**
   - **Expected:** For catalog UIs, list cards might expose typed fields (price, unit) without duplicating data in block repeaters.
   - **Happened:** `ctx.pages()` / `PageRef` never includes `fields` or `summary`; product cards cannot show price in a grid unless duplicated in block data or fetched elsewhere.
   - **Repro:** Register a product subtype with `price` in `fields`; add `ProductGrid` with `pagesField`; inspect resolved props.
   - **Direction:** Optional richer ref shape (e.g. `{ id, name, url, fields }`) behind a flag or page-type-aware resolver.

3. **No “list child pages” primitive for category views**
   - **Expected:** A category page could render live children (products) without manually curating `pagesField` selections (closer to PHP catalog patterns / tree navigation).
   - **Happened:** Frontend props expose breadcrumbs and page body/blocks only; block `ResolveContext` has no `children()` helper.
   - **Repro:** Publish a category with 12 child products; try to render them without naming each id in block data.
   - **Direction:** Block context helper or built-in “ChildPages” block kind that queries the tree by parent id + type filter.

4. **Public `url` config must match the served origin for client-side forms**
   - **Expected:** Relative form `action` paths or origin derived from the incoming request in dev/preview on arbitrary ports.
   - **Happened:** Form actions use the configured `cms url`; Playwright on `:3310` with default `CMS_URL=http://localhost:3300` made `fetch(action)` fail silently (empty errors, no success UI).
   - **Repro:** `vite preview --port 3310` without `CMS_URL=http://localhost:3310`; submit contact form with JS enabled.
   - **Direction:** Prefer relative `/_cms/forms/...` actions in resolved props, or document `CMS_URL` as mandatory per environment (PHP may infer from request).

### Nice-to-have

5. **Better Auth “schema mismatch” log on first request before migrations finish**
   - **Expected:** Quiet boot or migration before Auth checks tables.
   - **Happened:** One ERROR log about missing auth tables even though `getMigrations().runMigrations()` runs in the same boot path afterward (same as workbench).
   - **Repro:** Fresh SQLite; first HTTP hit.
   - **Direction:** Defer Auth init until after migrations or downgrade to debug when migrations are pending.

6. **Built-in Hero / ContactForm are unstyled**
   - **Expected:** Documented host responsibility (README already says unstyled).
   - **Happened:** Required site-local React overrides for a polished marketing site.
   - **Direction:** Optional `@tbtop/cms/react/themes/minimal` for dogfood hosts.

7. **Block placement key `article/product` for subtypes**
   - **Expected:** Clear docs/examples for subtype-scoped placements on custom blocks (`ProductDetail`).
   - **Happened:** Works when placement string matches editor rules, but easy to get wrong without reading tests.
   - **Direction:** Document subtype placement strings next to `definePageType({ base: "article" })`.

8. **Product JSON-LD**
   - **Expected:** `Product` or `Offer` schema for catalog items (original site is commercial).
   - **Happened:** Product pages use article subtype; schema picker stays in `Article` / `WebPage` family unless host adds custom `toSchema` on a block.
   - **Direction:** Optional `schemaTypes: ["Product"]` on product page type if schema.org Product is desired.

## Site-local workarounds (marked)

| Workaround | Location | Why |
|------------|----------|-----|
| Post-seed patch of `pagesField` / link ids | `src/fixture/seedSite.ts` | Finding #1 — keys → numeric ids after `seedFixtures` |
| `ProductDetail` reads `ctx.fields` on the product page | `src/blocks/implementations.ts` | Finding #2 — price only on detail URL, not in grid |
| `CMS_URL` / `BETTER_AUTH_URL` in Playwright webServer | `playwright.config.ts` | Finding #4 |

## Comparison notes (PHP package)

- **Slugs:** Ukrainian product titles transliterate to Latin slugs (`Лящ …` → `liashh-xolodnogo-kopcennia-steik`), consistent with ASCII slug policy.
- **Tree:** `category` → `article`/`product` subtype matches PHP `allowedChildren` for categories (articles under categories).
- **SEO:** Title template, Organization JSON-LD, CollectionPage `hasPart` for subcategories, breadcrumbs JSON-LD — behavior aligns with PHP demo expectations.
- **Redirects:** `/kontakty` → `/iak-nas-znaiti` 301 works like stored redirects in PHP.
- **Forms:** Honeypot + throttle + mailbox notification channel; no external mail sent (fixture uses JSONL sink like workbench).

## What worked well

- **End-to-end host wiring** mirrored the workbench: pack tarballs, `createCms`, layout header/footer, Better Auth admin, `/storage`, robots/sitemap, preview banner string in Ukrainian.
- **Page types:** `definePageType({ key: "product", base: "article" })` gave editable price/unit in admin and typed `ctx.fields` in blocks on the product page.
- **Custom blocks:** `defineBlocks` / `implementBlocks` with repeaters, `linkField`, and `pagesField` covered marketing sections without leaving the CMS.
- **Shared ContactForm** + notification mailbox: contact and cooperation pages reuse one shared block; submissions are editable/viewable in admin inbox.
- **Settings + single default locale (`uk`):** Site name, contacts, and SEO identity flow into meta tags and Organization JSON-LD without hardcoding in templates.
- **Boundary tests’ behavior** (redirects, 404 for drafts, sitemap excluding drafts) held up in smoke tests.
