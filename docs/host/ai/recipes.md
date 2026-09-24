# Recipes

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns "I think I need something the CMS does not have": compositions
that work today built from existing package parts, and gaps confirmed absent
in `src/` with the least-bad workaround. Block/page-type mechanics live in
`blocks.md`/`pages.md`; SEO detail lives in `seo.md`; this file only shows how
real hosts combined those primitives.

## Recipes — compositions that work today

### Site chrome (navbar/topbar/footer)

What you want: a persistent header/footer that isn't re-authored per page.
Compose it from layout blocks placed in the `header`/`footer` slots
(`Tbtop\Cms\Layout\Slots::HEADER`/`::FOOTER`, admin: Layout page) plus a host
React component that reads `cms.layout.header`/`cms.layout.footer` off the
shared Inertia prop and renders each slot's resolved blocks through the same
`json-render` renderer as page content. `Tbtop\Cms\Frontend\Http\Middleware\
ShareCmsProps::resolveLayout()` supplies that prop, cached per locale via
`CmsCache::remember('layout:{locale}', ...)` — see "Custom cached frontend
read" below for what that caching implies. Both real hosts hand-roll a chrome component rather than
using anything the package ships (there is no package `SiteChrome`):
`divotek_com_ua/resources/js/cms/SiteChrome.tsx`,
`autofocus/resources/js/cms/CmsPage.tsx` (chrome wrapping is inline in this
host's `CmsPage.tsx` rather than a separate file). divotek's chrome also has
one non-obvious extra: a `HEADER_ABSORBING_KINDS` map that inserts a spacer
`<div>` when the page's first block is a kind meant to sit under a fixed
header — see "Not yet expressible" below, this is a *workaround* for a real
gap, not something the package provides a hook for.

### Host-owned `CmsPage.tsx`

The README's stock recipe is a one-line re-export
(`export { default } from '@cms/CmsPage'`, see `resources/js/cms/CmsPage.tsx`
in this package for the file being re-exported). Both real hosts diverge from
that: `resources/js/pages/Cms/Page.tsx` stays the one-line re-export, but it
points at a **host-owned** `resources/js/cms/CmsPage.tsx` that is a rewrite,
not a copy, of the package's version. The package's own `CmsPage.tsx` is
intentionally thin — `<Head>` SEO tags, `JSONUIProvider`, `Renderer`, nothing
else. Hosts need it as the one place every frontend page passes through, so
they graft onto it: divotek's version wraps `ContactModalProvider` and
`SiteChrome` around the renderer, adds the `og:type`/`og:url` tags `SeoResolver`
leaves out, and injects a JSON-LD `<script>` tag from the
`schema` Inertia prop (`divotek_com_ua/resources/js/cms/CmsPage.tsx`).
autofocus's version adds a draft-preview banner keyed off `isPreview` and
swaps in its own block `registry` (`autofocus/resources/js/cms/CmsPage.tsx`).
Treat "own your `CmsPage.tsx`" as the norm, not the exception, the moment a
host needs chrome, a React context provider, or extra `<head>` tags — the
package's version has no extension point for any of those (`resources/js/cms/
CmsPage.tsx` is a single default export, not a slotted component).

### Block-derived JSON-LD (FAQ, Service, …)

`Block::toSchema(array $props, SchemaContext $ctx): ?array` is the extension
point for this (see seo.md's "Structured data (JSON-LD)" section for the
full contract, the built-in `FaqBlock` example, and a VideoObject sketch): a
block overrides it to return one schema.org node built from its own resolved
props, and `PageController` collects every block's non-null node into the
page's `seo.jsonLd`, after the page type's own node and before identity. No
middleware, no separate graph — this is now the primary path, and the
middleware workaround below is obsolete for any host currently on it.

A host that still needs to assemble its own graph outside `PageController`
(e.g. a non-CMS route, or a batch job rendering JSON-LD for many pages) uses
the same two public services `PageController` uses:
`Tbtop\Cms\Frontend\Seo\Schema\SchemaContextFactory::make($page, $locale,
$source)` to build the `SchemaContext`, then
`Tbtop\Cms\Frontend\Seo\SeoResolver::resolve($page, $locale, $breadcrumbs,
$ctx, $blockNodes)` passing that same context plus its own list of ready
nodes. `SeoResolver` and `SchemaContextFactory` are public services meant to
be recomposed, not subclassed.

### A "section" page type that can hold plain pages

`CategoryType::allowedChildren()` is hard-coded to `['category', 'article']`; a
host container type extending `BasePageType` is the fix — mechanics and both
host examples are in [pages.md](./pages.md#the-categorytype-gotcha). Both real
hosts register it through `config('tbtop-cms.pages.types')`; `PageTypeRegistry::
extend()` (see "A package that ships blocks/page types" below) is for packages.

### Content as seeders per section, idempotent by slug

Content authored by clicking in the admin lives only in that environment's
database — not diffable, not reproducible. Both real hosts write content as
Laravel seeders instead, one seeder per site section, idempotent on slug so a
repeat run doesn't duplicate pages: divotek's `database/seeders/Concerns/
SeedsPages.php` trait, method `upsertPage()`, looks the page up by
`Page::whereHas('slugs', ...)` and only calls `Tbtop\Cms\Page\Support\
PageSlugWriter::sync()` on first insert (see `BlogSeeder.php` for a concrete
seeder). One host doc-comment in that trait flags a real gap worth knowing before
copying the pattern: `withContactForm()` notes `Tbtop\Cms\Form\Support\
FormBlockLocator` only finds a form inside `$page->content` — a page without
an actual form block in its content renders dead CTA buttons. (The same
trait also works around `seo.description` not accepting a locale map — stale:
`LocalizedSeo::value()` reads locale maps now.)

### Cross-page data in a block (latest articles, projects grid, page pickers)

A block whose `resolve()` needs data from *other* pages (not just its own
envelope's `data`) queries `Tbtop\Cms\Page\Models\Page` directly inside
`resolve()`, using `ResolveContext::$page` for the current page's own
context. autofocus's `app/Cms/Blocks/Concerns/ResolvesPageCards.php` is the
concrete pattern: `pageCards($ids, ResolveContext $ctx)` takes an ID list
(populated by an admin field paired with `app/Cms/Blocks/Concerns/
PagePickerOptions.php::pageOptions(string $type)`, which lists pages of one
type for the picker's options), queries `Page::query()->with('slugs')
->whereIn('id', $selected)->where('status', PageStatus::Published)->get()
->keyBy('id')`, preserves editor-chosen order rather than query order, and
batches media lookups. A sibling `childCards()` auto-lists a page's own
published children by `sort_order` — no picker needed, useful for a
`SectionType` landing page listing its own services. There is no dedicated
package "page picker" field type — the picker is just an ID-array field on
the block's `form()` paired with these two host-written helper methods; see
`blocks.md` for the admin field DSL these methods plug into.

**Caching implication**: `Tbtop\Cms\Cache\CmsCache` is a
content-version-keyed cache (`CmsCache::remember()`/`bump()`), but in this package it wraps layout (`ShareCmsProps::resolveLayout()`), the
sitemap (`SitemapController`, via `rememberLive()`), settings and languages —
never block resolution. Page-content block resolution —
`Tbtop\Cms\Frontend\Http\Controllers\PageController::props()` calling
`BlockContentResolver::resolve($content, $page, $source)` — is **not**
wrapped in `CmsCache` anywhere in this package. A block's `Page::query()`
inside `resolve()` therefore runs on every HTTP request to that page, not
only on a cache miss. Header/footer layout blocks are the one exception
(cached, bumped on `LayoutSaved`/content writes). A host with an expensive
cross-page query in a page-content block needs its own caching around that
query (e.g. wrap the query itself in `CmsCache::remember()`, keyed by
whatever varies it) — the package gives no cache-for-free here.

### Contact modal / client-only interactivity shared across blocks

A modal or other client-only UI state that several unrelated blocks need to
trigger (a "Contact us" CTA button inside a Hero block, a pricing block, a
footer block) is ordinary React context, not a CMS concept — `json-render`
resolves block *props*, not shared client state. divotek's
`resources/js/cms/ContactModalContext.tsx` exports `ContactModalProvider`
(plain `useState` + memoized context value) and a `useContactModal()` hook
with a no-op fallback so a block rendered outside the provider (e.g. in a
Storybook-style preview) doesn't crash. It's mounted once, in the host-owned
`CmsPage.tsx` (see above), wrapping `SiteChrome` — every block rendered
through the page tree, and the chrome itself, share the one provider
instance. This composes cleanly with `json-render` because the two are
orthogonal: `json-render`'s registry maps a block `kind` to a component;
nothing stops that component from calling a React hook for state the CMS
never sees.

### A package that ships blocks/page types

`Tbtop\Cms\Block\BlockRegistry::extend(callable $extension): void` and
`Tbtop\Cms\Page\PageTypeRegistry::extend(callable $extension): void` exist for
exactly this: a package (not the host app itself) that can't ask the host to
edit `config/tbtop-cms.php`. Both are static, append-only, and **latch on
first read** — an extension registered after the registry singleton's first
`get()`/`has()`/`all()` call is silently ignored, so it must run from a
service provider's `boot()`, before any request resolves a page. Call shape,
verified in `tbtop-ecom/docs/storefront-cms-contract.md` and matching
`BlockRegistry`'s actual signature in `src/Block/BlockRegistry.php`:

```php
// A package's own ServiceProvider::boot() (or packageBooted() for spatie tools)
BlockRegistry::extend(fn (): array => [ProductGallery::class, ProductBuyBox::class]);
PageTypeRegistry::extend(fn (): array => [ProductType::class, CollectionType::class]);
```

Caveat: `extend()` is **not yet used
by a landed host or package** — `tbtop-ecom/packages/storefront` documents
this exact call in its contract but its `src/` tree has no `Blocks/` or
`PageTypes/` directory yet (the calls above are the *documented plan*, cited
here as the verified shape of the API, not as a working example). Both real
app hosts (divotek, autofocus) register their own blocks/page types the
plainer way instead — `config('tbtop-cms.blocks.types')` /
`config('tbtop-cms.pages.types')` arrays in their own `config/tbtop-cms.php`
— because they own that file and don't need the boot-order independence
`extend()` buys a package. Use `extend()` only when shipping a reusable
package; use the config arrays for a host's own blocks/page types.

The one thing that *is* landed and working end-to-end in `tbtop-ecom`: a
notifications bridge, registered the same "package boots into the package"
way but via `Tbtop\Cms\Notifications\Notifications::type(TypeDefinition::
make(...))` rather than a registry `extend()`. `StorefrontServiceProvider`
registers it from `packageBooted()` (Spatie's post-boot hook, not
`packageRegistered()`), with the reasoning spelled out in its own doc
comment: the `NotificationRegistry` singleton this writes into is bound by
`Tbtop\Cms\CmsServiceProvider::packageRegistered()`, and Laravel runs every
provider's `register()` before any provider's `boot()`, so reading it in
`packageBooted()` guarantees the binding already exists regardless of
provider discovery order — the same ordering guarantee `BlockRegistry::
extend()`/`PageTypeRegistry::extend()` give by latching on first *read*
instead. See `notifications.md` for `TypeDefinition`'s full field list.

### Reusable block content across pages

The name invites confusion: `Tbtop\Cms\Page\
Support\StarterBlocks` and `StarterBlockSync` are **not** a shared/reusable
block mechanism. `StarterBlocks::contentFor()` runs once, at page-creation
time only, copying the nearest ancestor category's starter-block *structure*
(`{id, kind}`, no field data) into the new page's own independent content
array; `StarterBlockSync::apply()` is the admin's explicit "sync existing
pages to the current starter structure" action, matched by a one-way `tpl_id`
provenance tag — it re-syncs structure, never links content live. Every
page's `content` column is its own independent copy from the moment it's
created; editing one page's block never affects another's. There is no
"Template" or reusable-block-instance concept anywhere in `src/` or
`resources/js/cms` — the only other hits for "template"
are `SeoTemplates` (title/description string templates, unrelated) and
`Notifications/Template*` (notification message templates, unrelated). If a
host genuinely needs one block instance shared live across many pages, there
is no package primitive for it — see "Not yet expressible" below.

### Replacing or adding an admin page

`Tbtop\Cms\Admin\CmsPanel::pages()` reads `config('tbtop-cms.admin.pages',
[])` — a name-keyed array of admin `Page` classes
(`config/tbtop-cms.php` → `admin.pages`, e.g. `'dashboard' =>
DashboardPage::class`) — filters out `null` entries, and hands the rest to
the panel. A host adds a page by appending a new key, or replaces one of the
package's own pages by overriding an existing key with a different class (or
`null` to remove it outright). Scaffolding: `tbtop/admin`'s `php artisan
make:tbtop-page` command (`Tbtop\Admin\Commands\MakePageCommand`, not part of
this package) generates `App\Admin\Pages\{Name}Page` into
`app/Admin/Pages/`, but does not register it — the command's own output says
so (*"Register {$class} in your panel/config pages list"*). `cms-starter-kit`
documents this flow in its README's "Adding admin pages" section but has no
landed example page in `app/Admin/Pages` — confirm the exact class shape
(constructor signature, required methods) against a package `Admin/Pages/*`
class before writing one, since no host example exists to copy from yet.

### Custom cached frontend read

`Tbtop\Cms\Cache\CmsCache::remember(string $key, Closure $fn): mixed` is a
plain content-version-namespaced cache, safe for a host to call directly for
its own frontend reads (not just package-internal ones — nothing scopes it to
`src/`). Cache entries are invalidated wholesale by
`Tbtop\Cms\Cache\InvalidatesContentCache` bumping the version counter on any
of `PageSaved`, `PagePublished`, `PageUnpublished`, `PageDeleted`,
`PageRestored`, `LayoutSaved`, `SettingsSaved`, `LanguagesChanged` — a host
read cached through `CmsCache` is invalidated automatically by any of those,
with no host-side cache-clearing code needed. Use `rememberLive()` instead of
`remember()` when the cached value's correctness depends on a page's
publish/unpublish schedule window (see `NextScheduleTransition` — it caps the
TTL to the next scheduled transition so a cached read never outlives it); use
plain `remember()` for everything else. This is the same primitive the
package uses for its own layout and sitemap reads (see "Cross-page data"
above) — a host doing its own expensive cross-page query inside a block's
`resolve()` should wrap that specific query the same way, since block
resolution itself is not cached by the package.

### Draft preview links for stakeholders

`Tbtop\Cms\Frontend\PreviewLink::for(Page $page, string $locale): string`
builds a Laravel signed URL (`URL::temporarySignedRoute('tbtop-cms.page',
now()->addHours($ttl), ['path' => ...])`, TTL from
`tbtop-cms.preview.link_ttl_hours`, default 72h) against the same catch-all
route a normal page view hits. `PageController::hasPreviewAccess()` accepts
either an authenticated admin (`CurrentAdmin::canAccess()`) or a request
carrying that valid signature (`$request->hasValidSignature()`) — either path
lets `PageResolver::resolve()` run with `withDrafts=true`, surfacing a draft,
scheduled, or expired page that a normal visitor's request would 404 on.
Anyone with the link can view the draft without an admin session; nothing
scopes the signature to one recipient, so it's a share-the-link model, not a
per-person grant. Nothing this package's own admin UI does needs
host code — `PreviewLink` is a plain constructible service; a host wanting a
"Copy preview link" button just resolves it from the container and calls
`->for($page, $locale)`.

## Not yet expressible — verified absent

### Extra layout slots beyond header/footer

`Tbtop\Cms\Layout\Slots` is two hardcoded class constants (`HEADER`,
`FOOTER`) and `Slots::all(): array` returns exactly those two — no config
key, no registry, nothing extensible (`config/tbtop-cms.php` has no
`layout`/`slots` section at all). A host wanting a third slot (a sidebar, a
promo bar) cannot add one through configuration. Least-bad workaround: model
the extra region as an ordinary block placed in page `content` at a fixed
position (e.g. always the first content block on relevant pages, or a
`BasePageType::template()` override that pre-seeds it), rather than a real
layout slot — it won't be admin-editable from the Layout page the way
header/footer are, only from each page's own content editor.

### Appending nodes to the package's JSON-LD graph

The page's own schema.org node type IS extendable — a host class extending
`Tbtop\Cms\Frontend\Seo\Schema\SchemaType`, registered via
`config('tbtop-cms.seo.schema_types')` or `SchemaTypeRegistry::extend()`
(see seo.md), can add a new key to the `schema_type` picker. What is still
not possible is *appending extra nodes on top of* the one the registry
already produces — `WebSite`/`BreadcrumbList`/identity plus one page node is
the fixed shape `SeoResolver::jsonLd()` returns, no `Event::dispatch`, no
`Macroable`. A host wanting a second, block-derived node (FAQPage, Product,
Service, …) alongside the page's own still needs the "Block-derived JSON-LD"
workaround above (a parallel graph shared under a different Inertia prop) —
temporary until `Block::toSchema()` ships.

### Custom richtext node renderers

`Tbtop\Cms\Richtext\RichtextNodeRenderer::renderNode()` is a hardcoded `match`
over a fixed set of Lexical-style node types (`paragraph`, `heading`, `quote`,
`list`, `listitem`, `code`, `linebreak`, `text`, `link`/`autolink`) with a
`default` arm that recurses into the node's children and drops the node's own
wrapper — no registry, no config, no `extend()`. A host authoring a custom
richtext node type (e.g. an embedded video) gets that node's inner text
rendered unwrapped, silently, with no error. No workaround exists inside this
renderer; the only escape is not putting a custom node type in richtext at
all — model it as a separate block instead, which has a real extension point
(`BlockRegistry`).

### Host-added health checks in `cms:doctor`

`Tbtop\Cms\Health\HealthCheckRunner::defaultChecks()` is a hardcoded list of
12 of the package's own `Checks\*` classes. The runner's constructor does
accept an injected `?array $checks`, but every real call site
(`DoctorCommand::handle(HealthCheckRunner $runner)`, admin's
`SiteHealthPage`/`HealthReportQuery`) resolves it from the container with no
bound override, so `$checks` is always `null` in practice and
`defaultChecks()` always wins. No config array, no registration method exists
for a host to add its own check into `cms:doctor` or the Site Health admin
page. Workaround: write the host's own Artisan command (or extend
`HealthCheckRunner` manually — `new HealthCheckRunner([...HealthCheckRunner::
defaultChecks(), MyCheck::class])`, resolving each via `app()` — and call it
separately); it will not appear inside `cms:doctor`'s own output or the
package's Site Health page.

### Hero `absorbsHeader`-style block-to-chrome coupling

A block has no way to signal anything to the chrome/layout that
renders around it. There is no equivalent of "this Hero wants a transparent,
overlaid header" as a block prop or context value the chrome could read.
divotek's old (pre-package) CMS had exactly this flag; the port to this
package explicitly could not carry it over — the host's own port brief
(`divotek_com_ua/PORT_PROMPT.md`) instructs: *"если блок старого сайта
опирается на возможность, которой в новой CMS нет (например, `absorbsHeader`
у hero) — не изобретай обходной путь молча, зафиксируй и спроси"* (don't
silently invent a workaround, flag it and ask). The workaround divotek's
`SiteChrome.tsx` actually ships is a client-side lookup table
(`HEADER_ABSORBING_KINDS`, matching specific block `kind` strings) that
conditionally renders a spacer `<div>` instead — chrome-side knowledge of
which block kinds need it, not a signal the block itself sends. Treat this as
the least-bad pattern until/unless the package grows a real hook (e.g. a
`ResolvedBlock`-level chrome hint).

### Per-page custom Inertia component

`PageController::component()` returns the single value at
`config('tbtop-cms.render.component')` (default `Cms/Page`) and calls
`Inertia::render($this->component(), ...)` once, unconditionally.
`PageTypeInterface`/`BasePageType` have no `component()`/`inertiaComponent()`
method. Every page, regardless of type, renders through the exact same
Inertia component. A page type wanting materially different page-level
markup (not achievable by varying the block content) has no hook to render a
different component; the only lever is what blocks/`template()` an source-
backed page type supplies (see `pages.md`), all still passed through the one
`Cms/Page` component.

### Block-level SEO fields

`Block`, `BlockEnvelope`, and `ResolvedBlock` carry no `seo`-shaped field or
method.
`PageOwnSeo` and `SeoResolver` operate exclusively on the `Page` model's own
`seo` JSON column — there is no per-block equivalent (e.g. an FAQ block
contributing its own meta description fragment). SEO is page-scoped only;
see `seo.md` for what that page-level surface covers.

### Scheduled/A-B content

`Tbtop\Cms\Cache\NextScheduleTransition` and `Page`'s `published_at`/
`unpublish_at` columns give page-level publish/unpublish scheduling only —
`NextScheduleTransition::compute()` exists solely to cap a cache TTL at the
next go-live/go-dark boundary.
No per-block scheduling and no A/B-variant concept exists anywhere in the
package — a block cannot be scheduled independently of its page, and there is
no mechanism for serving two variants of the same content to different
visitors. A host needing either would build it entirely outside the package
(e.g. a host-owned model keyed by page + variant, resolved before content
reaches `BlockContentResolver`).
