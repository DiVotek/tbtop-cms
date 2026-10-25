# CMS findings — Lumen Studio dogfood

Site: `sites/studio` · stack: TanStack Start + `@tbtop/cms` tarball · DB: SQLite · Locales: en, de.

## What worked well

- **Fixture seeding** (`seedFixtures`) produced a credible multi-locale site in one shot; slugs, shared blocks, layout, and redirects matched PHP-style expectations.
- **Locale routing** (default unprefixed, `/de/…` prefix, hreflang alternates) behaved like the PHP package in manual checks and Playwright.
- **Category + article** tree is enough for a blog: journal category, posts as `article` + `journal` subtype with custom fields (tags, reading time).
- **SEO surface** is strong: title template, JSON-LD (WebSite, Organization, Article), chunked sitemap, `robots.txt`, canonical/alternate links — all visible in SSR HTML.
- **Redirects**: manual fixture redirect (`/old-contact`) and **auto-redirect after slug change** (`/contact` → `/get-in-touch`) both returned 301 in E2E.
- **Forms**: POST `/_cms/forms/{locale}/{page}/{block}` with JSON `{ ok: true }`, honeypot field bound, notifications appended to local mailbox — same contract as documented.
- **Admin**: Better Auth login, pages editor, languages, settings, inbox, layout/shared blocks, custom host `definePage` (“Studio overview”) all reachable.
- **Publishing rules**: scheduled post (2037) and draft post correctly 404 on the frontend; live siblings appear in category JSON-LD `hasPart` (CMS-side).

---

## Gaps and bugs

### 1. No first-class RSS/Atom feed

| | |
|---|---|
| **Severity** | nice-to-have |
| **Expected** | Optional feed route or helper (many marketing sites want `/feed.xml`). |
| **Happened** | Nothing in `cms.handle()`; host must query pages and serialize RSS. |
| **Repro** | Search `packages/node/src` for `rss` / `feed` — no matches. |
| **Direction (hypothesis)** | Expose something like `cms.feed.rss({ parentId, locale, limit })` or a documented recipe using `pages.children()` + `pageUrl()`. |

**Site workaround:** `src/server/feed.ts` + `/feed.xml` branch in the splat route GET handler.

---

### 2. Category index pagination not in frontend props

| | |
|---|---|
| **Severity** | should-fix |
| **Expected** | Category pages expose child summaries (or cursor/limit) for blog indexes without extra DB calls. |
| **Happened** | `loadPage` props include category page fields and JSON-LD `hasPart` (up to 100), but no paginated list API for templates. `pages.children()` has `limit` only — **no offset/cursor**. |
| **Repro** | `packages/node/src/content/pages.ts` → `children(..., { limit })`; build a 3+ post journal and note you must fetch all children to paginate in the host. |
| **Direction** | Add `offset`/`afterId` to `pages.children()` or a `cms.site().pages.listDescendants(categoryId, { page, perPage })` helper aligned with PHP if it exists there. |

**Site workaround:** `journalIndexForCategory()` in `src/server/blog.ts` loads all live children, filters tags, slices in memory (`PAGE_SIZE = 2`).

---

### 3. Host helpers not exported from package entry

| | |
|---|---|
| **Severity** | nice-to-have |
| **Expected** | Public helpers for translatable text and safe href matching documented block patterns. |
| **Happened** | `translationText` / `safeHref` live under `src/content/*` but are **not** re-exported from `@tbtop/cms` (had to duplicate `fieldText` / local `safeHref`). |
| **Repro** | `import { translationText, safeHref } from "@tbtop/cms"` → TS2724/2305. |
| **Direction** | Export stable host utilities from the main entry (or `@tbtop/cms/content`). |

---

### 4. TanStack Start loader + `createServerFn` footgun

| | |
|---|---|
| **Severity** | should-fix (docs) |
| **Expected** | Workbench pattern (`loadCmsPage({ data: location.href })`) works in production preview. |
| **Happened** | Passing full `location.href` into a server function sometimes produced **`Invalid URL`** during SSR (500, empty `lang`). Using `{ pathname, search }` + explicit `CMS_URL` base fixed it. Importing `@tanstack/react-start/server` in route modules breaks client bundle unless logic stays in server-only modules. |
| **Repro** | TanStack Start production build + `vite preview`; loader calls server fn with `location.href` only. |
| **Direction** | Document required `CMS_URL`, pathname-based server fns, and “no server imports in route components” in Node host guide; align workbench loader with the safe pattern. |

**Site workaround:** `loadStudioPage` in `src/server/loadPage.ts` (server-only) with `{ pathname, search }`.

---

### 5. Rich block content via JSON textareas (admin UX)

| | |
|---|---|
| **Severity** | nice-to-have |
| **Expected** | Repeatable/translatable structures (service cards, stats) have first-class fields or repeaters. |
| **Happened** | Fastest path was seeding locale-keyed JSON blobs and resolving with `pickLocaleList()`; admin forms use `textarea` “JSON per locale” placeholders — not editor-friendly. |
| **Repro** | Edit `ServiceGrid` / `StatBand` in admin — opaque JSON. |
| **Direction** | Repeater field in `@tbtop/admin` DSL, or nested translatable groups in block `form()`. |

---

### 6. Better Auth “schema mismatch” log on cold start

| | |
|---|---|
| **Severity** | nice-to-have |
| **Expected** | Silent boot until migrations finish. |
| **Happened** | First request logs `ERROR [Better Auth]: Database schema mismatch` (missing auth tables) even though `boot()` immediately runs Better Auth migrations — scary in dev/CI logs. |
| **Repro** | Fresh SQLite + `vite preview`; hit any URL once. |
| **Direction** | Defer `betterAuth()` plugin schema check until after `runMigrations()`, or document as benign first-hit noise (workbench README mentions this). |

---

### 7. Contact form success UX in custom React shell

| | |
|---|---|
| **Severity** | should-fix (verify) |
| **Expected** | `useCmsForm` shows success after click in a styled wrapper (as in workbench). |
| **Happened** | Playwright UI flow did not show `[role=status]` within 15s on `/get-in-touch`; **JSON POST via `request.post(action)` succeeded** with `{ ok: true }`. |
| **Repro** | Custom `StyledContactForm` + click submit in browser E2E (see git history of `e2e/smoke.spec.ts`). |
| **Direction** | Compare with workbench `ContactForm` wiring; confirm client action URL/origin when `CMS_URL` differs from browser origin; may be site-only. |

**Site workaround:** E2E asserts CMS endpoint via HTTP client; UI still renders the form.

---

### 8. No bundled media in fixture story

| | |
|---|---|
| **Severity** | nice-to-have |
| **Expected** | Demo images via CMS media refs on pages/blocks. |
| **Happened** | Hero uses text-only; `/storage` disk works but seed did not populate sample uploads (Node workbench README notes no media library tile). |
| **Direction** | Fixture helper to copy sample assets into `localDisk` + set `page.image` / block image fields. |

---

## PHP parity notes (informal)

- Locale prefix stripping, sitemap hreflang, form POST shape, and auto-redirect on slug change **felt aligned** with PHP tests/docs referenced in `packages/node/test`.
- Not exhaustively diffed; parallel golfstrimfish site may cover additional edge cases.
