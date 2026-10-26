# Pages

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns the `Page` model, page types and hierarchy rules, slugs/URLs,
redirects, source-backed pages, revisions, preview links, layout slots, and
seeding pages. Block content *inside* a page is owned by
[./blocks.md](./blocks.md); the `seo` column's fields by
[./seo.md](./seo.md); routes/middleware/`Cms::frontendRoutes()` wiring by
[./wiring.md](./wiring.md).

## The `Page` model

`src/Page/Models/Page.php`, table `tbtop_pages`.

Translatable (spatie/laravel-translatable, `$translatable`), stored as
`['uk' => 'Головна', 'en' => 'Home', ...]` per locale: `name`, `title`,
`heading`, `summary`, `body`. Read/write a locale with
`getTranslation('name', $locale)` / `setTranslations('name', [...])`, or
assign the whole array through `fill()`/mass assignment. `body` is richtext;
`PageController` ships it in the frontend `page` prop as rendered HTML
(`page.body`, this locale then the default language, `''` when empty). What
the frontend renders as blocks is `content`, the block list.

Plain (not translatable): `type` (always a top-level type), `subtype`
(nullable — see Subtypes and type fields), `fields` (array cast, the type
fields' stored values), `source_id`, `parent_id`, `status`,
`published_at`, `unpublish_at`, `content` (array cast, block envelope — see
blocks.md), `starter_blocks` (array cast, per-child-type starter structure
— see Starter blocks below), `image` (a media id, not a URL — resolve with
`MediaUrlResolver`), `seo` (array cast — see seo.md), `is_indexable`,
`is_system`, `sort_order`, `lock_version` (optimistic-lock counter, bumped
in SQL by `PageObserver` on every real update — not mass-assignable; an
admin form save claims it atomically, so of two saves loaded at one version
only one writes and the other gets the conflict toast).

`created_by`/`updated_by` are stamped by `TracksAuthors`, never
mass-assigned. Every field a seeder needs is in `$fillable`.

### Status and "live"

`PageStatus` (`src/Page/Enums/PageStatus.php`) is only `Draft` / `Published`
— the author's stored intent. Scheduling layers on top via
`published_at`/`unpublish_at`, both nullable datetimes. What actually shows
on the frontend is never `status` alone:

- `Page::scopeLive()` — the query scope every frontend-facing read uses
  (`PageResolver`, `SitemapEntryBuilder`, `FormSubmitController`): status is
  `published` AND (`published_at` is null or in the past) AND
  (`unpublish_at` is null or in the future).
- `Page::isLive()` — the same rule for an already-loaded instance.
- `Page::displayState()` — the admin's derived read model
  (`PageDisplayState`: `Draft`/`Scheduled`/`Published`/`Expired`), folding
  the schedule into the plain status for the pages list and dashboard.

Publish now: set `status` to `Published` and leave `published_at` null. The
first publish stamps `published_at` with the current time (so the page has a
`datePublished`), and a later unpublish/republish keeps that stamp. A date you
set yourself is never overwritten. Schedule a future go-live or go-dark by
setting the datetime columns; no separate "scheduled" status value exists. A host writing pages
outside the admin only needs `scopeLive()`/`isLive()` itself if it
re-implements a frontend read path — `PageResolver` already applies it.

## Page types

`PageTypeInterface` (`src/Page/Contracts/PageTypeInterface.php`): `key()`,
`label()`, `allowedChildren()`, `requiresParent()`, `maxDepth()`, `base()`,
`fields()`, `resolveFields()`, `childSubtype()` (see
Subtypes and type fields and Page references), plus the source-backed trio
below. `BasePageType` (`src/Page/Types/BasePageType.php`)
gives every method a content-only, leaf-type default (`allowedChildren()`
`[]`, `requiresParent()` `false`, `maxDepth()` `1`, not source-backed) — a
host type extends it and overrides only what differs.

Built-ins, all in `src/Page/Types/`:

| Type | `allowedChildren()` | `requiresParent()` | `maxDepth()` |
|---|---|---|---|
| `PageType` (`page`) | `[]` | `false` | `1` (default) |
| `CategoryType` (`category`) | `['category', 'article']` | `false` | `3` |
| `ArticleType` (`article`) | `[]` | `true` | `1` (default) |

Register a host type via `config('tbtop-cms.pages.types')` (a list of
class-strings) or `PageTypeRegistry::extend(fn (): array => [...])` — the
same pattern `BlockRegistry::extend()` uses (see blocks.md). `extend()` must
run before the registry's first `get()`/`has()`/`all()`/`keys()` call: an
extension registered after boot has latched is stored but never read
(`src/Page/PageTypeRegistry.php`). Duplicate keys across config and every
extension throw `InvalidArgumentException` at boot.

`PageTypeRegistry` instance methods (`has()`, `get()`, `all()`, `keys()`,
`isSourceBacked()`) — call them on the resolved instance
(`app(PageTypeRegistry::class)->isSourceBacked($type)`); they are not
static, and a static call fails. Only `extend()` and
`flushExtensions()` are static.

`isSourceBacked()` returns `false` for an
unregistered type rather than throwing — `pages.type` is an unconstrained
string column, so a page outlives its type's registration (a host removed
the class from config) and must still render its stored content instead of
500ing. `PageController` and the admin edit page both rely on this.

### The `CategoryType` gotcha

`CategoryType::allowedChildren()` is hard-coded to `['category', 'article']`
— nothing else. A services page, a portfolio grid, a landing-page section:
none of these are `article`, so a plain `category` cannot parent them. Both
real hosts hit this and both solved it the same way: a host-owned container
type.

`autofocus/app/Cms/PageTypes/SectionType.php` (key `section`, children
`['service', 'guide']`, `maxDepth() = 2`) parents its `ServiceType` and
`ArticleType` (key `guide`), both `requiresParent() = true` leaves.
`divotek_com_ua/app/Cms/PageTypes/SectionType.php` does the same for its
services/portfolio/blog tree (children `['page', 'article', 'section']`,
`maxDepth() = 3`, sections can nest).

The stock admin gives such a container no way to be picked as a parent:
`PageForm::parentQuery()` (the parent select in the create form and the
edit sidebar) lists pages of type `category` only. So a page under a host
`section` is not placed from the stock forms; the host sets `parent_id`
itself (its own admin page or action, a seeder, an import) and
`PageHierarchy` still validates it.

The rule: a section needing children that are not built-in
`category`/`article` gets a host `SectionType` (or similarly named
container) rather than an attempt to reconfigure `CategoryType` — its
`allowedChildren()` is not configurable, only overridable by subclassing
`BasePageType` directly. `PageHierarchy::assert()`
(`src/Page/Support/PageHierarchy.php`) enforces `allowedChildren()`,
`requiresParent()`, and `maxDepth()` on every create, parent-or-type change
and restore from the trash, walking the full ancestor chain and descendant
tree — a violation throws `DomainException` from the
`creating`/`updating`/`restoring` observer hooks, not just a form-level
warning (the admin's restore action shows it as an error notice).

### Starter blocks

> Starter blocks seed optional sections, not a type's fixed parts — see
> [placement.md](./placement.md) for where each kind of content goes.

A category can define `starter_blocks: {article: [...], category: [...]}`
— structure only (`{id, kind}`, no `data`) per child type, edited on the
category's own edit page. `StarterBlocks::contentFor()`
(`src/Page/Support/StarterBlocks.php`) seeds a brand-new child's `content`
from it at create time only: walking up from the immediate parent
(inclusive), the nearest ancestor category whose *own* starter for that
child type is non-empty wins — closer beats farther, and an ancestor with
no starter of its own is transparent, not a dead end. Only `article` and
`category` children inherit anything (`page` has no starter-block slot). A
new category with no ancestor starter falls back to copying its immediate
parent's own content *structure* (kind + order, not text).

`StarterBlockSync::apply()` (`src/Page/Support/StarterBlockSync.php`) is the
"apply to existing" action: force-syncs a category's current starter onto
every already-existing descendant of that type, in the same scope
`contentFor()` would have used at creation (stops descending into a
subcategory with its own starter for that type). The sync is structural
only: a page block whose `tpl_id` still exists in the starter is left
completely untouched (content belongs to the page, not the starter); a
`tpl_id` no longer present is dropped with its content; a starter element
with no matching page block is inserted **inactive** with empty data (an
empty block must never go live uninvited); a block added directly on the
page, with no `tpl_id`, is never touched.

## Slugs and URLs

One `PageSlug` row per `(page, locale)` (`src/Page/Models/PageSlug.php`,
table `tbtop_page_slugs`): `slug` (this page's own segment) and `path` (the
full parent-joined path, no leading/trailing slash). `Page::pathFor($locale)`
reads it off the `slugs` relation.

`PageSlugWriter::sync()` (`src/Page/Support/PageSlugWriter.php`) is the only
writer. It runs automatically from `PageObserver::saved()` on **every**
`Page::save()` — a host never calls it directly except when seeding wants to
force a specific slug (see Seeding below) or after a manual
`Languages`/locale change (`backfillLocale()`). It returns `bool`: whether
any of the page's own slug rows changed (`false` for an error page).

**No language rows → no slugs → 404.** `sync()` writes slug rows only for
locales that exist in the languages table. In a fresh database with no
languages seeded, saving a page produces an empty `tbtop_page_slugs` table,
`pathFor()` returns `null`, and the page does not resolve (404). Seed
languages before creating pages — this bites most often in tests.

- A non-empty slug override (from `Page::$pendingSlugOverrides`, keyed by
  locale) always wins over the stored/derived slug, for both new and
  existing rows — this is what makes an existing page's slug editable. An
  empty/missing override keeps the current slug, or falls back to a
  name-derived, `Str::slug()`-ed base for a locale with no row yet.
- Collisions get a numeric suffix (`-2`, `-3`, ...), retried up to 100
  attempts against a `UniqueConstraintViolationException` race.
- Changing a parent's slug cascades: every descendant is re-synced so its
  `path` stays joined correctly.
- **System pages are exempt.** `is_system` pages ignore any slug override
  outright — their URL is not author-editable, matching their
  status/type/parent locks (see `PageObserver::guardSystemStatus()`).
- **An error page gets no `PageSlug` row at all**, not even an empty one —
  see "Error pages" below. `PageSlugWriter::sync()`/`writeLevels()` skip a page
  where `Page::isErrorPage()` is true before deriving any base.

### The home page

The root `is_system` page (`is_system = true`, `parent_id = null`,
`error_class = null`) maps to the **empty path** —
`PageSlugWriter::slugBase()` returns `''` for it, so its `PageSlug.path` is
`''`, and `PageResolver::resolve('', $locale)` finds it for `/`. There is no
separate "home page" flag or config key read at request time; `is_system` +
no parent + no `error_class` *is* the home-page identity. `PageSeeder`
locates the existing home page by `(is_system, type, error_class: null)`,
never by slug — `is_system` + `type = 'page'` alone is no longer unique,
since both error pages share that pair too (see "Error pages" below).

### Error pages

Two more system pages, one per HTTP status class: `error_class` (nullable,
unique string column on `tbtop_pages`, cast to
`Tbtop\Cms\Page\Enums\ErrorClass`) is `'4xx'` or `'5xx'`, never a specific
status code — 522 and 500 share the same page. `Page::isErrorPage()` is
`error_class !== null`. Like the home page they are `is_system = true`,
`parent_id = null`, `type = 'page'`, which is exactly why the seeder cannot
tell the three apart by `(is_system, type)` alone — it locates each error
page by `error_class` specifically, and the home page by `error_class:
null` in addition to `(is_system, type)`.

Differences from the home page:

- **No `PageSlug` row, ever** — an error page is never resolvable by path.
  It renders only through `Cms::errorResponse(Request $request, int
  $status): Response`, which looks it up by `ErrorClass::fromStatus($status)`
  (`src/Frontend/Errors/ErrorPageResolver.php`), not by path.
- `error_class` is immutable after creation — `PageObserver::updating()`
  throws if it's dirty, same shape as `guardSystemStatus()`.
- `is_indexable` is seeded `false`, and `SitemapEntryBuilder` excludes
  `error_class IS NOT NULL` outright — an error page stays out of the sitemap
  even if an editor flips the flag back on. The admin treats an error page
  like any other system page: no special column, no hidden field.
- `PageDuplicator::duplicate()` clears `error_class` to `null` on the copy,
  same as it clears `is_system` — a duplicated error page is an ordinary page.
- 4xx pages render directly from the DB through the normal `Cms/Page`
  pipeline. 5xx rendering is wrapped in a try/catch: if it throws, the
  response falls back to a static Blade view
  (`resources/views/errors/fallback.blade.php`) with no DB/Inertia/settings
  dependency of its own — see `docs/host/ai/seo.md` and `wiring.md` for the
  full fallback chain and the `errors.handle`/`errors.statuses` config.

### Building a URL

`PageUrl::build($path, $locale)` (`src/Page/PageUrl.php`) applies the
locale-prefix rule: the default locale gets no prefix (`/about`); every
other active locale is prefixed (`/en/about`). `SetLanguage` middleware
does the inverse on the way in — it redirects a request that explicitly
prefixes the *default* locale (`/uk/about` when `uk` is default) down to
the un-prefixed canonical URL, so there is exactly one canonical path per
(page, locale).

Use the `Cms` facade (`src/Facades/Cms.php` / `src/Cms.php`) rather than
`PageUrl`/`PageResolver` directly from host code:

```php
Cms::resolve(string $path, ?string $locale = null): ?Page   // path → live Page, current locale by default
Cms::url(Page $page, ?string $locale = null): string        // Page → locale-prefixed path
Cms::breadcrumbs(Page $page, ?string $locale = null): array // list<{label, path, url}>, home › live ancestors › page
```

`Cms::resolve()` only ever returns a *live* page (no `$withDrafts` param
exposed) — it delegates to `PageResolver::resolve($path, $locale, false)`.
`Cms::breadcrumbs()` (and the `breadcrumbs` prop, same method) walks
`AncestorChain::forPage()` (root-first), prepends the seeded site root
(`is_system`, no parent, not an error page — it is a *sibling* of top-level
pages, not their parent) unless it is already in the chain, and appends the
page itself. Only a live ancestor or site root (`Page::isLive()`) makes a
crumb: a published article under a draft category stays reachable and in the
sitemap, but its trail skips the category (`[Home, Post]`). The page itself is
never filtered, so a preview shows the trail a visitor will see. The filter is
in `Breadcrumbs`, not `AncestorChain` — hierarchy checks and starter blocks
still see draft ancestors — and the root's liveness is read fresh on each call
(only its id is memoized). Each crumb is labelled with its `name` falling back to `title`
(`title` feeds `<title>` and tends to be SEO-length), current locale falling
back to the default locale. `{parent}` gets the same label. Fewer than two crumbs
after the filter yields `[]`, so the home page has no trail: `/services` → `[Home, Services]`,
`/blog/post` → `[Home, Blog, Post]`. The JSON-LD `BreadcrumbList` is built
from the same trail (see seo.md). `{parent}` does **not** read it — that
variable uses `Breadcrumbs::ancestors()`, the real live ancestry without root
or self, so it names the nearest live ancestor and is empty when there is
none.

### Redirects

`src/Redirect/*`. `Redirect` (table `tbtop_redirects`): `locale` (nullable
— a locale-specific row is tried before a locale-`null` fallback row),
`from_path`, `to_path`, `status_code`, `is_auto`.

**Automatic**, on every slug change: `PageSlugRedirects` observer
(`src/Redirect/Observers/PageSlugRedirects.php`), wired independently of
`PageObserver` in `ContentEventsWiring::wireRedirectMaintenance()`. On
`PageSlug::updated()` with a changed `path`, `RedirectWriter::recordMove()`
retargets any existing auto-redirect that pointed at the *old* path (a
chain of moves collapses to one hop, not N) and upserts a new `from → to`
auto row, `301`, `is_auto = true`. On `PageSlug::saved()`,
`RedirectWriter::clearAt()` deletes any redirect whose `from_path` equals the
saved slug's path, unconditionally — a slug moved back onto a previously
redirected-from path must resolve to the page, not loop through its own
stale redirect.

**Manual**, via the admin Redirects page: a host or editor adds rows
directly, `is_auto = false`.

**External targets** (`to_path` starting `http://`, `https://`, or `//`,
tolerant of backslash-for-slash browser normalization —
`RedirectPath::isExternal()`) are rejected at the admin form layer unless
their host is listed, case-insensitive exact match, in
`config('tbtop-cms.redirects.allowed_external_hosts')`
(`src/Admin/Pages/Support/RedirectValidator.php`) — an empty list (the
default) blocks every external target. The check runs on the admin form
save, not inside `RedirectResolver` — a redirect written outside the
validated form path is not re-checked at resolve time.

**Resolution order**, in `PageController::__invoke()`
(`src/Frontend/Http/Controllers/PageController.php`): page lookup
(`PageResolver::resolve()`) → if not found, redirect lookup
(`RedirectResolver::resolve()`, following up to 10 chained hops, cycle-safe)
→ if neither, fire `PageNotFound` and throw a 404. A source-backed page
whose `source()` resolves to `null` also falls through this same
not-found → redirect → 404 path (see Source-backed pages).

## Preview access and preview links

`PageController::hasPreviewAccess()` grants draft visibility to two callers:
an authenticated admin (`CurrentAdmin::canAccess()` — the same rule
`EnsureCmsAdmin` enforces on panel routes, independent of the host's regular
end-user guard) or a request carrying a valid Laravel signed URL
(`$request->hasValidSignature()`). Anyone else gets the published-only
lookup — an unpublished path 404s (or falls back to a redirect) for them.

`PreviewLink::for(Page $page, string $locale)` (`src/Frontend/PreviewLink.php`)
builds that signed URL against the same `tbtop-cms.page` named route the
frontend uses, applying `PageUrl`'s locale-prefix rule so a
non-default-locale draft doesn't 404 against the default-locale path. TTL:
`config('tbtop-cms.preview.link_ttl_hours', 72)`. It expects a locale the
page has a saved slug in: for one without, it signs the site root.

The editor's **Preview link** action (`PageEditStatusBar`, only on a page that
is not live and not an error page) shows one read-only field per
frontend-active locale (`Languages::frontendActive()` order) whose saved slug
gives the page a path (`pathFor($locale) !== null`): field
`previewUrl_{locale}`, labelled with the language name, filled with that
locale's signed link when the modal opens. **Copy** copies the first one.
Every save writes a slug for each frontend-active locale, so a missing one is
data drift; with no slug anywhere the modal shows
`tbtop-cms::pages.preview.no_slug` and no fields or Copy.

The `isPreview` prop is `! $page->isLive()` — true for draft, not-yet-
scheduled, and past-`unpublish_at` alike, since reaching the page at all
with any of those means the caller already had preview access.
`PageController` also forces `seo.noindex = true` whenever `isPreview` is
true, regardless of the page's own indexability settings.

## Source-backed pages

> `template()` is not how a content-only type draws its fixed parts — see
> [placement.md](./placement.md), rule 8.

A page type can be backed by a domain object the host owns elsewhere (a
product, a listing — anything with its own table). Three methods on
`PageTypeInterface`, all defaulted to off by `BasePageType`:

```php
isSourceBacked(): bool;          // true = pages of this type are created externally, never by hand in cms
source(Page $page): ?object;     // loads the domain object by (type, source_id); null if gone
template(Page $page): ?array;    // block content to render when $page->content is null
```

`Page::$source_id` (nullable string, `(type, source_id)` indexed — not
unique, nothing stops two pages claiming the same source) identifies which
domain object a source-backed page shows. There is no `source_type`
column: `type` already says what kind of source it is.

Render path (`PageController::props()`): `$content = $page->content ??
$type->template($page) ?? []` — an explicit `content` on the page always
wins over the template (a host can still hand-edit a source-backed page's
content by writing the column directly; the *form* is what's locked, not
the column).

A content-only (not source-backed) type can return `template()` too: a page
of it created in the admin starts with a copy of those blocks in `content`
(`TypeTemplateContent`, called from `PageFormWriter::fillContent()` on create when the page
inherits no category starter blocks), so the editor shows them and they
survive every save. Each copied block gets a fresh ULID `id` (any `id` in the
template is replaced); `active`, `sort` and `data` may be left out and default
to `true`, its position and `[]`. Pages
written straight to the database (a seeder, an import) get no copy: they
render the template only while their `content` is null, and the first editor
save writes the empty list the editor showed. Such code writes `content`
itself.

`source($page)` is resolved once per request and handed to
every block as `ResolveContext::$source`; a source-backed type whose
`source()` returns null (deleted, unpublished on the source's own side)
renders the same not-found → redirect → 404 path as an unpublished page —
defense in depth for the window between the source disappearing and the
sync retiring the page.

Editor behavior: `PageEditPage`/`PageEditSidebar` hide the block-content
tabs, starter blocks, and type select for a source-backed page and show a
read-only Source card instead (type label, `source_id`, and the source's
`Stringable` form when available —
`src/Admin/Pages/Support/PageEditSourceCard.php`). `PageForm::save()`
skips writing `content`/`starter_blocks` entirely rather than writing null
— the stored value (template fallback trigger, or a host-written override)
survives untouched. Creating a page of a source-backed type, or retyping an
existing page into one, is rejected **server-side** in `PageForm`, not just
hidden in the UI. Duplicating one is refused the same way:
`PageDuplicator::duplicate()` throws a `DomainException`
(`tbtop-cms::pages.validation.type_source_backed`), and the pages list hides
the Duplicate row action for those rows.

`tbtop-ecom/docs/storefront-cms-contract.md` documents the intended host
usage pattern — a storefront package registering `ProductType`,
`VariantType`, `CollectionType` via `PageTypeRegistry::extend()`, each
loading its aggregate by `source_id` and rendering one shared per-kind
template. Treat it as the pattern to follow, not as a description of what
has landed — at the time of writing it is a frozen-contract planning doc
for an unbuilt package, though its `PageTypeInterface` snippet matches what
actually shipped (`.changes/feat-source-backed-pages.md`).

**Assumptions worth knowing:** `source_id` is trusted to be set by the
host's own sync — nothing here writes it or enforces a source-backed page
has one; `source($page)` must handle a null `source_id` itself.
`template()` is called on every render with no caching between the call
and block resolution — an expensive template build costs a full request.

## Subtypes and type fields

> A type field holds only what the standard page fields cannot — see
> [placement.md](./placement.md) before declaring one.

A subtype is a key and a set of fields, not a type of its own.
`PageTypeInterface::base()` returns the type's own key by default (a
top-level type); a host type that is a kind of another returns that type's
key:

```php
final class ConcertType extends BasePageType
{
    public function key(): string { return 'concert'; }
    public function base(): string { return 'event'; }   // a host top-level type works too

    public function fields(S $s): array
    {
        return [$s->text('artist')->label('Artist')];
    }
}
```

- **Storage.** `type` holds the base, `subtype` the subtype key (or null).
  Tree rules (`allowedChildren`, `requiresParent`, `maxDepth`, starter
  blocks) always come from `type`, i.e. the base — a subtype's own
  overrides of them are never read.
- **Invariant on every write.** `PageObserver` (on `saving`, when `type` or
  `subtype` is dirty) throws a `DomainException` for any writer — admin,
  seeder, host code — when `type` is a registered subtype (its `base()` is
  not its key; `pages.validation.type_is_subtype`), or when `subtype` is set
  but is not a registered type whose `base()` is `type`
  (`pages.validation.subtype_invalid`). A save that leaves both untouched is
  not checked, so a page whose stored subtype lost its registration stays
  editable; an unregistered `type` is rejected by the tree rules as before.
  Writes past Eloquent (query builder, raw SQL) are not checked:
  `cms:doctor` lists stored violations under `page_type_pairs`, and a
  subtype whose `base()` names no registered top-level type under
  `page_subtype_bases` (not thrown at registry boot, which would take the
  site down for one misdeclared class).
- **Editor.** "Type" lists the types whose `base() === key()`. The sidebar
  "Subtype" select lists only the subtypes of the page's current type
  ("— none —" first), is hidden when that type has none, and writes the
  `subtype` column; `type` is untouched. Changing "Type" while a subtype is
  stored is rejected on `type` (`pages.validation.type_change_subtype`);
  clear the subtype first — the select stays visible after a "Type" change
  until it is cleared, so both go in one save. The create modal has one subtype select per base
  type that has subtypes, named `subtype_{base}`; the one for the picked
  type is saved. The pages list gets a Subtype column and filter once any
  subtype is registered.
- **Leaf type follows the category.** A page given a category (a `category`
  parent) becomes an `article`; an article whose category is removed becomes
  a `page`. Picking `page` by hand drops a stale parent; picking `article`
  requires a category (`pages.validation.article_needs_category`). Both
  article rules apply only while the `article` type's `requiresParent()` is
  `true`: a host `ArticleType` override returning `false` keeps root
  articles as articles and lets the create modal make one. Under any
  other container whose `allowedChildren()` includes `page` (a host
  `section`), a page stays a page. The edit form asks to confirm while the
  save would change the type.
- **Pages list tabs.** "All", then one tab per `type` that a non-trashed
  page has — registered types in registration order, then any type that
  outlived its registration — then "Deleted". A tab is named by the type key
  (`?tab=section`) and labelled by `pages.types.{key}` when that translation
  exists, else the type's `label()`, else the raw key. A host base type
  (`section`, `event`) gets its own tab; subtypes share their base's tab and
  use the Subtype filter.
- **`childSubtype()`.** A category type (or subtype) may return the key of a
  subtype of `article`; an article that arrives under that category without
  a subtype of its own — created there, or a page that just became an
  article — gets it. The parent's subtype is asked first, then its base; a
  key that is not a registered subtype of `article` is ignored. An article
  that already has a subtype keeps it. `BasePageType` returns `null`.
- **Type fields.** `fields(S $s): array` (empty from `BasePageType`) is a
  list of ordinary form fields. The General tab shows, under the page's own
  fields, a section for the base type's fields and then one per subtype of
  the base, each shown only while the "Subtype" select picks it — picking
  another subtype shows its fields before a save. In the form a base field
  is named `field_{name}`, a subtype's `field_{subtype}__{name}`; both are
  saved under the bare name into the page's `fields` column. A subtype
  field's `required` applies only while its subtype is picked. A save
  writes the base's and the picked subtype's fields and merges into
  `fields` — keys the form does not save (a former subtype's) are kept, so
  switching the subtype and back never loses data.
- **Field names.** A page's type fields are the base's `fields()` merged
  with its subtype's, one per name: **same name → the subtype's
  declaration wins**. Its rules validate the save and its kind resolves
  the value; the base's field of that name is hidden, unvalidated and not
  saved while that subtype is picked, and the base's `resolveFields()` does
  not receive the value. The reverse-reference index follows the same
  declaration. Two subtypes of one base may declare the same name too: a
  page has only one of them, and both read and write the same `fields` key.
- **Site.** `page.fields` ships the base's and then the subtype's fields
  (a subtype key wins on a clash), translatable values flattened to the
  request locale; every block reads them through `ResolveContext::field()`.
  `page.subtype` ships next to `page.type`. To compute or reshape values,
  override `resolveFields(array $input, ResolveContext $ctx): array` — the
  default runs `FormResolver` over `fields()`, and the input is the flattened
  `fields` column. A throw is logged (`page.fields_resolve_failed`) and that
  type's fields drop out; in a strict render it propagates.
- **Blocks.** `placements()` may name a subtype key: the block is then
  offered in "+ Add block" on pages of that subtype only (a page is offered
  the blocks placed on its `type` and on its `subtype`).
- `BlockContentResolver::resolveWithFields()` is the render entry:
  `{blocks, fields}`; `resolve()` returns the blocks only.

## Page references

A `pages` field picks several pages; a page type can read, in turn, the
pages that pick it with `Cms::referencedBy()` inside its `resolveFields()`.

```php
// SpecialistType — base 'article'
public function fields(S $s): array
{
    return [$s->pages('services')->label('Services')->of('article', 'legal_service')->max(10)];
}

// LegalServiceType — base 'article'
/** @return array{specialists: list<array{id: string, name: string, url: string}>} */
public function resolveFields(array $input, ResolveContext $ctx): array
{
    return [
        ...parent::resolveFields($input, $ctx),
        'specialists' => Cms::referencedByLinks($ctx->page, 'services', 'specialist'),
    ];
}
```

- **Field.** `$s->pages('name')` (`src/Admin/Fields/Pages.php`) is a
  searchable multiple select of pages; it stores their ids as strings, in
  the order picked. `->of(type, ?subtype)` restricts it to a type (and
  subtype) and may be called more than once; with no `of()` any page is
  accepted. `->max(n)` caps the count. The search matches the name in the
  admin's locale or the default content locale, shows at most 20, leaves
  out trashed pages and the page being edited, and includes drafts. `of()`
  is enforced by the search only: save (rule `page_ref`) rejects an id that
  does not exist (force-deleted included) or is the page being edited, and
  accepts a page whose type no longer matches `of()` or that is trashed. So
  retyping or trashing a target does not block saving the pages that
  reference it; a trashed reference works again once it is restored. Not translatable —
  `->translatable()` throws.
- **Site.** The field resolves to `list<{id: string, name: string, url:
  string}>` of the **live** pages, in stored order, name and url in the
  request locale (same rules as a page link: the name falls back to the
  default/first filled locale; a page with no slug in that locale, or no
  name in any locale, is left out). Unknown, trashed and unpublished ids drop out
  silently. Works in a type's `fields()` and in a block's `form()` alike.
- **Reverse lookup.** `Cms::referencedBy(?Page $page, string $field,
  ?string $from = null, ?string $locale = null): list<Page>` returns the
  live pages whose type field `$field` holds `$page`, by `sort_order` then
  name; `$from` narrows to sources whose `type` or `subtype` is that key. A
  null page gives `[]`, so `$ctx->page` passes as is.
  `Cms::referencedByLinks()` takes the same arguments and returns the same
  `{id, name, url}` list a `pages` field resolves to. The type calls either
  from its own `resolveFields()` and picks the key, so nothing else writes
  into `page.fields`. For more per entry (a photo, a position), map the
  `Page` models from `referencedBy()`. Type the key in the override's
  `@return array{...}` (see Page prop types) — the base's keys stay typed
  from its form.
- **Index.** The lookup reads `tbtop_page_refs` (`page_id`, `field`,
  `target_id`, `position`), which `PageRefs` (a `Page` observer) rewrites
  for a page on every save; a force delete cascades. Only a page type's
  `fields()` are indexed. A page whose types change at deploy time (a
  pages field added to an existing type, a type class rebound) is indexed
  on its next save — run `php artisan cms:page-refs:rebuild` after such a
  deploy, or after importing pages with a raw query. `cms:install` runs it
  too.
- **Gotchas.** A draft can be picked but never appears on the site until it
  is live, on either side of the reference. The reverse lookup covers
  page-type fields only: a `pages` field inside a block is resolved forward
  but is invisible to `Cms::referencedBy()`.

## Page prop types

`cms:blocks:types` also writes the `page` prop's zod schemas and TS types to
`pages.generated.ts` beside `blocks.generated.ts`
(`config('tbtop-cms.pages.types_path')`; `false` turns it off), and its
`--check` covers that file too. One variant per registered type and
subtype, discriminated by the literal pair `type`/`subtype`:

```tsx
import type { CmsPage, PageOf } from "./cms/pages.generated";

const { page } = usePage<{ page: CmsPage }>().props;
if (page.subtype === "concert") page.fields.artist; // narrowed

function ConcertHeader({ page }: { page: PageOf<"concert"> }) { /* … */ }
```

- **Exports.** `<key>Fields` / `<key>Page` (const and type) per type key in
  camel/Studly case, `cmsPage` / `CmsPage` (the union), and `pageProps` /
  `PageOf<K>` keyed by type or subtype key. A base type's variant has
  `subtype: null`; a subtype's has its base's `type` literal.
- **Fields.** Built like the resolver: the base's part, the subtype's over
  it (a subtype key wins). A part keeping the base `resolveFields()` is typed
  from its `fields()` (same rules as a form-driven block); an override is
  typed from its `@return array{...}`. An override without one prints a
  warning and makes the variant's `fields` a `z.looseObject`: the other
  part's keys stay typed, any other key is `unknown`.
- **Skipped.** A subtype whose `base()` is not a registered top-level type
  gets no variant, with a warning (`cms:doctor` reports it as
  `page_subtype_bases`).
- **Runtime.** Nothing parses the page prop with these schemas; they are
  exported for a host that wants to. The error-page fallback (no published
  DB page) sends `type: "page"` with `fields: {}`, so it misses the schema
  when the host gives the `page` type fields.

## Revisions

Config-gated content-snapshot history per page, `config('tbtop-cms.revisions.keep', 25)`
— the retention cap *and* the on/off switch in one setting. `0` or `null`
disables the feature entirely, including the very first create snapshot.
Wired as a second, independent `Page` observer
(`src/Page/Observers/PageRevisions.php`, registered in
`ContentEventsWiring::wirePageRevisions()`, deliberately separate from
`PageObserver` so turning revisions off can never risk the structural
hierarchy/slug guards).

`PageRevisionWriter::snapshot()` fires on every `Page::save()` that changes
an "editorial" field (`name`, `title`, `heading`, `summary`, `body`,
`image`, `content`, `fields`, `seo`, `is_indexable`) — a sort-order-only drag reorder
or a bare status toggle does not create a revision. Deliberately excluded:
`status`, `type`, `subtype`, `parent_id`, `sort_order` — workflow/structural state
`restore()` never touches, so restoring old content can never resurrect an
old status or move the page in the tree. Every save keeps only the newest
`keep` rows per page.

## Layout slots

`src/Layout/*`. Exactly two slots, `Slots::HEADER` / `Slots::FOOTER`
(`src/Layout/Slots.php`) — no third slot, no way to register one. One
`Layout` row per slot (table `tbtop_layouts`, unique on `slot`), holding a
block-content envelope in `content` — same shape as `Page::$content` (see
blocks.md), edited on the admin Layouts page.

`LayoutResolver::resolve()` (`src/Layout/LayoutResolver.php`) returns
`array<string, list<ResolvedBlock>>` keyed by slot, resolved through the
same `BlockContentResolver` pages use, and exposes it as `cms.layout` via
`ShareCmsProps` middleware, not per-page. A missing `tbtop_layouts` table
(mid-migration) or a null-content row resolves to an empty list for that
slot rather than erroring.

Seed a layout with a plain `updateOrCreate` keyed by `slot`:
`Layout::query()->updateOrCreate(['slot' => Slots::HEADER], ['content' =>
HeaderBlocks::content()])`.

## Seeding content

The recommended way to load a site's content is idempotent PHP seeders, not
clicking through the admin — every real host does this. The pattern (from
`divotek_com_ua/database/seeders/Concerns/SeedsPages.php`,
`HomePageSeeder.php`, `LayoutSeeder.php`):

- **Key by slug, not id.** Find an existing page by
  `whereHas('slugs', fn ($q) => $q->where('slug', $slug))`, or the home page
  by `is_system` + no parent — never by a hard-coded primary key, so a
  seeder is safe to re-run against a database that already has the row.
- **`updateOrCreate`-style `fill()` + `save()`.** `PageObserver::saved()`
  runs `PageSlugWriter::sync()` automatically on every save, so a seeder
  normally does not need to call it. Call `PageSlugWriter::sync($page,
  ['uk' => $slug, 'en' => $slug])` explicitly only to *force* a specific
  slug on first creation (an author-chosen override) rather than let it
  derive from `name`.
- **Translatable columns as a locale map.** `name`, `title`,
  `summary` (etc.) take `['uk' => '...', 'en' => '...']` directly through
  `fill()`.
- **`content` is a plain PHP array of block envelopes** — see blocks.md for
  the `{id, kind, data, sort, active}` shape; a seeder builds a
  `list<array<string, mixed>>` and assigns it like any other fillable field.
- **Check where the text landed.** After a seeder or import, count field
  fills per type ([placement.md](./placement.md#after-modelling-or-importing-count-the-fills)):
  a type whose `body` is empty on most pages is a modelling error.

The package's own `PageSeeder` (`database/seeders/PageSeeder.php`, run by
`cms:install`, verified against `tests/Page/PageSeederTest.php`) follows
exactly this shape for the home page — `Page::firstOrCreate(['is_system' =>
true, 'type' => 'page', 'error_class' => null], [...])` — reading
`name`/`title` from `config('tbtop-cms.pages.home')`. It seeds the two error
pages the same way, keyed by `error_class` alone — `Page::firstOrCreate(
['error_class' => ErrorClass::ClientError], [...])` and the `ServerError`
counterpart — reading `name`/`title` from
`config('tbtop-cms.pages.errors.4xx')`/`.5xx`. All three reads use the
default locale from `Languages::defaultLocale()`. `cms:install` also runs
`LanguageSeeder`, which upserts every row in
`config('tbtop-cms.languages.seed')` by `locale` (`firstOrCreate`, so a
second run is a no-op). A host seeds further content the same way, from its
own `DatabaseSeeder` or a dedicated `cms:seed`-style command — nothing
restricts seeding to `cms:install`'s two seeders. A host already running
`PageSeeder` in production gets the two new error-page rows the next time it
runs the seeder — nothing else needs re-running.

## Cache: what a host must do

Every domain write that changes frontend-visible content already bumps the
shared content-cache version — `InvalidatesContentCache`
(`src/Cache/InvalidatesContentCache.php`) is registered in
`ContentEventsWiring::wireContentCacheInvalidation()` against `PageSaved`,
`PagePublished`, `PageUnpublished`, `PageDeleted`, `PageRestored`,
`LayoutSaved`, `SettingsSaved`, `LanguagesChanged`. A host that writes
`Page`/`Layout` rows through the normal Eloquent model — `save()`, seeders
included — gets `PageSaved`/`PagePublished`/`PageUnpublished` for free; the
observer dispatches them regardless of caller. A query-builder write
(`Page::query()->where(...)->update([...])`) fires no model events at all,
so it bumps nothing. `PageDeleted` and `PageRestored` are not raised by the
observer: only the admin (page edit, pages list) dispatches them. A host
that deletes or restores pages itself dispatches the event or bumps the
cache.

A host only needs to bump the cache itself
(`app(\Tbtop\Cms\Cache\CmsCache::class)->bump()`) if it writes to
`tbtop_pages`/`tbtop_layouts` through a path that bypasses Eloquent events
entirely — a raw `DB::table(...)->update(...)`, a direct SQL data fix.
Plain Eloquent seeders and admin edits need nothing extra.

One gap the events do **not** cover: `published_at`/`unpublish_at` crossing
its threshold with no write happening at that instant —
`PagePublished`/`PageUnpublished` fire on the *status transition at save
time*, not on live visibility actually changing. `CmsCache::rememberLive()`
caps its TTL to the nearest upcoming schedule transition for exactly this
reason (`NextScheduleTransition`) — cached reads that depend on live scope
should use `rememberLive()`, not `remember()`, to stay correct across an
unattended schedule boundary.

## Events

`src/Page/Events/*`. `PageSaved`/`PagePublished`/`PageUnpublished` are raised
from `PageObserver`'s `created()`/`updated()` hooks inside `DB::afterCommit()` — a batched write inside a transaction
(e.g. `StarterBlockSync::apply()`) fires once per page, only after commit.
A save that writes no column but changes a slug (a slug-only edit-form save)
fires no Eloquent `updated`, so `saved()` raises `PageSaved` for it instead:

- `PageSaved(Page $page, bool $wasCreated)` — every finished save, create or
  update, regardless of what changed.
- `PagePublished(Page $page)` — status transitions into `Published`, on
  create or update.
- `PageUnpublished(Page $page)` — status transitions out of `Published` on
  update.
- `PageDeleted(Page $page, bool $forced)` — soft or forced delete **done in
  the admin**; the observer never dispatches it.
- `PageRestored(Page $page)` — a soft-deleted page restored in the admin;
  likewise not dispatched by the observer.

`PagePublished`/`PageUnpublished` fire on the **status column transition**,
not on live-visibility change — see the Cache section above for what that
means for scheduled pages.
