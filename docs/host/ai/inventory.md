# Inventory

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns the lookup tables: every config key, Artisan command, route,
Inertia prop, extension contract, event, Settings key, section ability,
database table, facade method, and built-in block/page type. It is the one
file where restating a signature is not a no-op. The *why* behind any of
these belongs to the sibling that owns the topic (wiring.md, blocks.md,
pages.md, seo.md, notifications.md, recipes.md) — this file only points there.

## 1. `config/tbtop-cms.php`

Source of truth: `config/tbtop-cms.php` itself, published via `vendor:publish --tag="tbtop-cms-config"`. Every key below is confirmed by a `config('tbtop-cms.…')` read in `src/` or `database/seeders/`.

| Key | Type | Default | Meaning | Used by |
|---|---|---|---|---|
| `languages.seed` | `list<array{name:string,locale:string,is_default:bool,is_admin_active:bool,is_frontend_active:bool,sort_order:int}>` | one English entry | Rows seeded into `tbtop_languages` on `cms:install` / `LanguageSeeder` | wiring.md |
| `pages.types` | `array<string,class-string>` | `[]` | Host page-type classes merged into `PageTypeRegistry` (`Page/PageTypeRegistry.php`) | pages.md |
| `pages.types_path` | `string\|false\|null` | `null`, falls back to `pages.generated.ts` next to the blocks types file | Output path for the `page` prop's zod schemas/TS types written by `cms:blocks:types` (`BlockTypes/PageTypeScriptEmitter.php`); `false` turns it off | pages.md |
| `pages.home.name` | `string` | `'Home'` | Name given to the seeded home page (`database/seeders/PageSeeder.php`) | wiring.md |
| `pages.home.title` | `string` | falls back to `pages.home.name` | Title given to the seeded home page | wiring.md |
| `pages.errors.4xx.name` / `.title` | `string` | `'Page not found'` | Name/title given to the seeded 4xx error page (`database/seeders/PageSeeder.php`) | pages.md |
| `pages.errors.5xx.name` / `.title` | `string` | `'Something went wrong'` | Name/title given to the seeded 5xx error page | pages.md |
| `blocks.types` | `array<string,class-string<Block>>` | `[]` | Host block classes merged into `BlockRegistry` (`Block/BlockRegistry.php`) | blocks.md |
| `blocks.strict` | `bool` | `false` | When true, `BlockContentResolver` throws on an unknown block kind instead of skipping it | blocks.md |
| `blocks.discover` | `array<string,string>` | `[]` | Namespace → folder pairs scanned for concrete `Block` subclasses (`Block/BlockDiscovery.php`), registered after `blocks.types` in relative-path order | blocks.md |
| `blocks.registry_path` | `string\|false\|null` | `null`, falls back to `registry.generated.ts` next to the types file | Output path for the generated catalog entries + component map (`BlockTypes/RegistryEmitter.php`); written only when the component folder exists; `false` turns it off | blocks.md |
| `blocks.components_dir` | `string` | `./blocks` | Import prefix of the block components relative to the registry file; `<Kind>.tsx` exporting `<Kind>` is expected under it | blocks.md |
| `blocks.types_path` | `?string` | `null`, falls back to `resource_path('js/cms/blocks.generated.ts')` | Output path for `php artisan cms:blocks:types`'s generated zod schemas/TS types; override per-invocation with `--path=` | blocks.md |
| `seo.schema_types` | `array<string,class-string<SchemaTypeInterface>>` | `[]` | Host schema.org node classes merged into `SchemaTypeRegistry` (`Frontend/Seo/Schema/SchemaTypeRegistry.php`) | seo.md |
| `seo.robots.disallow` | `list<string>` | `[]` | Extra paths robots.txt closes to crawlers, on top of the admin prefix and `/login` (`Frontend/Seo/RobotsRegistry.php`) | seo.md |
| `seo.sitemap.enabled` | `bool` | `true` | Turns the sitemap routes on/off (`Cms.php`, `Frontend/Seo/RobotsTxt.php`) | seo.md |
| `seo.sitemap.per_chunk` | `int` | `1000` | Fallback URLs-per-chunk when the Settings key `seo.sitemap_per_chunk` is unset (`Frontend/Seo/SitemapEntryBuilder.php`, capped by an internal `MAX_PER_CHUNK`). Two similarly-named knobs exist — this config key is the inline default; `seo.sitemap_per_chunk` in Settings (§7) is the live, admin-edited override | seo.md |
| `preview.link_ttl_hours` | `int` | `72` | Hours a signed draft-preview link stays valid (`Frontend/PreviewLink.php`, admin status bar) | pages.md |
| `cache.enabled` | `bool` | `true` | Turns on `CmsCache`'s cross-request cache for frontend content reads; admin reads always stay live | wiring.md |
| `cache.ttl` | `int` | `3600` | Seconds a cached entry lives before recomputing (`Cache/CmsCache.php`) | wiring.md |
| `cache.store` | `?string` | `null` | Named cache store to use; `null` uses the host's default store | wiring.md |
| `settings.secret` | `list<string>` | `['mail.password', 'notifications.telegram.bot_token']` | Dotted `Settings` keys that are always encrypted at rest and excluded from `forFrontend()` | §7 below |
| `frontend.middleware` | `list<class-string>` | `[SetLanguage::class]` | Middleware stack applied to routes registered by `Cms::frontendRoutes()` | wiring.md |
| `ssr.enabled` | `bool` | `env('TBTOP_CMS_SSR', false)` | Inertia SSR for CMS frontend responses only (`Frontend/Http/Middleware/EnableSsr.php`); the host's global `inertia.ssr.enabled` must stay `false` or admin responses get SSR'd too | wiring.md |
| `forms.throttle` | `string` | `'10,1'` | Rate-limit spec (`max,minutes`) applied to the form-submit route (`Cms.php`) | blocks.md |
| `forms.store` | `bool` | `true` | Whether `FormSubmitController` persists a `FormSubmission` row; `false` still fires notifications but stores nothing | notifications.md |
| `forms.honeypot_field` | `?string` (or `false`) | `'website'` | Field name exposed to public form blocks as `props.honeypotField` and enforced by `FormSubmitController`; `null`/`false` disables the check and stops exposing the prop | blocks.md |
| `redirects.allowed_external_hosts` | `list<string>` | `[]` | Hosts an external redirect target is allowed to point at; exact, case-insensitive match (`Admin/Pages/Support/RedirectValidator.php`) — listing `example.com` does not allow `sub.example.com` | pages.md |
| `not_found.ignore` | `list<string>` | `NotFoundNoise::DEFAULT_IGNORE` | Case-insensitive substring patterns; a matching 404 path is dropped before it's counted (`NotFound/Listeners/RecordsNotFoundHit.php`). The on/off switch itself is the Settings key `seo.not_found.enabled`, not this config key | seo.md |
| `not_found.auto_prune` | `bool` | `true` | Whether `CmsServiceProvider` schedules `cms:not-found:prune` daily itself | §2 below |
| `errors.handle` | `bool` | `true` | Whether `CmsServiceProvider` auto-registers an exception-handler `renderable()` that turns a whitelisted HTTP status into the matching error page; `false` leaves exception handling entirely to the host, `Cms::errorResponse()` still works when called directly | seo.md, wiring.md |
| `errors.statuses` | `list<int>` | `[404, 500, 503]` | Statuses the auto-registered renderable intercepts; any other status stays stock Laravel | seo.md |
| `revisions.keep` | `int` | `25` | Page content-revision snapshots kept per page, oldest pruned past this (`Page/Support/PageRevisionWriter.php`, `Page/Observers/PageRevisions.php`); `0` or `null` disables the revisions feature entirely — no snapshots taken, not even on creation | pages.md |
| `audit.retention_days` | `int` | `365` | Days an activity-log row is kept before pruning (`CmsServiceProvider`, min-clamped to 1) | wiring.md |
| `audit.auto_prune` | `bool` | `true` | Whether `CmsServiceProvider` schedules the audit-log prune itself | §2 below |
| `profile.password_change` | `bool` | `true` | Shows/hides the password-change UI on `ProfilePage`; disable on hosts where passwords are managed elsewhere (SSO) | wiring.md |
| `render.component` | `string` | `'Cms/Page'` | Inertia component name `PageController` renders for every CMS frontend page | pages.md |
| `render.root_kind` | `string` | `'Fragment'` | Root block `kind` `PageSpecAssembler` wraps a page's resolved blocks in | pages.md |
| `render.root_view` | `?string` | `null` | Overrides the Inertia root Blade view for CMS responses; checked by `Health/Checks/InertiaRootViewCheck.php` | wiring.md |
| `roles` | `bool` | `false` | `false` leaves every `tbtop-cms.*` section ability open to any admin; `true` hands all of them to host-defined gates (`Admin/Support/CmsAbilities::enabled()`) — an unanswered ability then denies | §8 below |
| `admin.autoregister` | `bool` | `true` | Whether `CmsServiceProvider` auto-registers the admin panel/routes itself | wiring.md |
| `admin.prefix` | `string` | `'admin'` | URL prefix the admin panel and its routes mount under | §3 below |
| `admin.guard` | `string` | `'web'` | Auth guard the admin panel, login, and `EnsureCmsAdmin` use | wiring.md |
| `admin.model` | `class-string` | `'App\\Models\\User'` | Host user model class; read wherever the package needs the host's user type (`UserKeyColumn`, `AdminUsers`, `AuthorNames`, profiles) | wiring.md |
| `admin.pages.*` | `array<string,class-string>` | 24 slots, one per built-in admin page (dashboard, profile, login, languages, pages, page_edit, redirects, not_found, seo_report, media_library, site_settings, seo_settings, notification_settings, site_health, layout, layout_edit, shared_blocks, shared_block_edit, blocks, form_submissions, form_submission, users, audit_log, audit_activity) | Maps a logical admin page slot to the class that implements it; swap a value to override the built-in page | wiring.md |

## 2. Artisan commands

Source of truth: command classes under `src/Console/`, `src/Admin/Commands/`, `src/NotFound/Commands/`, `src/Seo/Commands/`, `src/Page/Refs/`, and their registration/scheduling in `CmsServiceProvider`.

| Command | Signature/options | Purpose | Scheduled? |
|---|---|---|---|
| `cms:install` | `{--force : Overwrite published config files} {--skip-migrations : Do not run database migrations}` | Publish config, run migrations, seed default languages and home page, rebuild the page references index | No |
| `cms:doctor` | (none) | Run CMS health checks and report issues (`HealthCheckRunner::defaultChecks()`; among them `untyped_blocks`, `block_discovery` — a `blocks.discover` entry that registers no class or is malformed — `broken_shared_references` — page/layout rows per container whose `shared` points at a missing shared block or one of another kind — and `unregistered_block_rows` — rows whose kind is no longer registered; both count trashed pages and inactive rows, and both are the rows the editor lists under Problem blocks; `page_type_pairs` — stored pages whose `type` is a subtype key or whose `subtype` is not a registered subtype of their `type`, per pair with a count and the first ids, trashed included; `page_subtype_bases` — subtypes whose `base()` is unregistered or itself a subtype; `unknown_schema_types` — page types whose `schemaTypes()` list a key `SchemaTypeRegistry` does not know, which the page skips). Exit 1 only on an error-level check | No |
| `cms:admin:create` | `{email} {--name=} {--password=}` | Create or reuse a user and grant CMS admin access | No |
| `cms:admin:grant` | `{email}` | Grant CMS admin access to an existing user | No |
| `cms:admin:revoke` | `{email}` | Revoke CMS admin access from a user (refuses to revoke the protected owner) | No |
| `cms:not-found:prune` | (none) | Delete 404 log rows older than the Settings key `seo.not_found.retention_days` (default 90) | Yes — daily, if `tbtop-cms.not_found.auto_prune` is true (default true). `ScheduleWiring::wireNotFoundPruneSchedule()`, schedule name `tbtop-cms:not-found:prune` |
| `cms:seo:snapshot` | (none) | Run the SEO audit and write/update today's row in `tbtop_seo_audit_snapshots` | No — host must schedule via cron |
| `cms:page-refs:rebuild` | (none) | Recompute `tbtop_page_refs` from every page's (trashed included) type fields of kind `pages` | No |
| `cms:blocks:types` | `{--check} {--path=}` | Generate zod schemas + TS types for registered blocks from each `resolve()`'s `@return array{...}` docblock (from the form for a block without its own `resolve()`), plus `registry.generated.ts` (catalog entries + component map) when the component folder exists, and `pages.generated.ts` (the `page` prop per page type and subtype, pages.md "Page prop types") unless `pages.types_path` is `false`; `--check` compares every file it would write | No |

All eight registered via `CmsServiceProvider::configurePackage()` -> `->hasCommands([...])`.

Two more schedule entries exist but are not Artisan commands owned by this package:
- `activitylog:clean tbtop-cms --days={retention_days} --force` (from `spatie/laravel-activitylog`) — daily, if `tbtop-cms.audit.auto_prune` is true (default true). `ScheduleWiring::wireAuditPruneSchedule()`, schedule name `tbtop-cms:audit:prune`.
- An inline `$schedule->call(...)` heartbeat writing `SchedulerHeartbeatCheck::CACHE_KEY` — every minute, unconditional. `ScheduleWiring::wireSchedulerHeartbeat()`, schedule name `tbtop-cms:health:scheduler-heartbeat`.

## 3. Routes

Source of truth: `src/Cms.php` (`Cms::frontendRoutes()`) for the frontend; `src/Admin/CmsPanel.php` plus each `Page::path()` in `src/Admin/Pages/*.php` for the admin panel — page routing itself is wired by the `tbtop/admin` dependency, not raw `Route::` calls in this repo.

**Frontend (`Cms::frontendRoutes()`, `src/Cms.php`)** — group middleware: `[...config('tbtop-cms.frontend.middleware', [SetLanguage::class]), EnableSsr::class, InjectGtm::class, ShareCmsProps::class]`; the caller must wrap the call in `web` middleware itself.

| Name | Method | Path | Purpose |
|---|---|---|---|
| `tbtop-cms.form.submit` | POST | `_cms/forms/{locale}/{page}/{block}` | `FormSubmitController` — handles a form block submission; `{block}` is a placed block id or a `HasForm` form name (blocks.md); throttled by `tbtop-cms.forms.throttle` |
| `tbtop-cms.robots` | GET | `robots.txt` | `RobotsController` — dynamic robots.txt |
| `tbtop-cms.sitemap` | GET | `sitemap.xml` | `SitemapController@index` — sitemap index; only if `tbtop-cms.seo.sitemap.enabled` (default true) |
| `tbtop-cms.sitemap.chunk` | GET | `sitemap-{chunk}.xml` | `SitemapController@chunk` — one sitemap chunk; registered before the catch-all so it isn't shadowed by a page slug |
| `tbtop-cms.page` | GET | `{path?}` (catch-all, `.*`) | `PageController` — resolves and renders any CMS page; must be registered last |

**Not a route**: `ErrorPagesWiring::wireErrorPages()` registers a `renderable()` on the host's exception handler (`Illuminate\Contracts\Debug\ExceptionHandler::renderable()`), gated by `tbtop-cms.errors.handle` — see seo.md/wiring.md.

**Admin, registered directly by `CmsServiceProvider`:**

| Name | Method | Path | Purpose |
|---|---|---|---|
| `login` | GET (redirect) | `login` -> `{admin.prefix}/login` | `AdminWiring::wireAdminLoginRedirect()` — only registered if no route named `login` already exists |
| `tbtop.cms.logout` | POST | `{admin.prefix}/logout` | `LogoutController`, middleware `['web','auth:{admin.guard}']` |
| `tbtop.cms.link.page-search` | POST | `{admin.prefix}/link/page-search` | `LinkPageSearchController` — the `link` field's page picker; `{search}` → `{options:[{value,label,names}]}`, `{value}` → `{option}` (`names` is the page's name per content locale, feeding the label placeholders). Middleware `['web','auth:{admin.guard}',EnsureCmsAdmin]`; not scoped to `live()`, capped by `tbtop-admin.relation.search_cap` (default 50) |

**Admin panel pages** (`CmsPanel`, prefix = `config('tbtop-cms.admin.prefix','admin')`, guard = `config('tbtop-cms.admin.guard','web')`):

| Page class | Path (relative to prefix) |
|---|---|
| DashboardPage | `dashboard` |
| ProfilePage | `profile` |
| LoginPage | `login` |
| LanguagesPage | `languages` |
| PagesPage | `pages` |
| PageEditPage | `pages/{page}/edit` |
| RedirectsPage | `redirects` |
| NotFoundLogPage | `not-found` |
| SeoReportPage | `seo-report` |
| SiteSettingsPage | `site-settings` |
| SeoSettingsPage | `seo-settings` |
| NotificationSettingsPage | `notification-settings` |
| SiteHealthPage | `site-health` |
| LayoutPage | `layout` |
| LayoutEditPage | `layout/{slot}/edit` |
| SharedBlocksPage | `shared-blocks` |
| SharedBlockEditPage | `shared-blocks/{sharedBlock}/edit` |
| BlocksPage | `blocks` |
| FormSubmissionsPage | `form-submissions` |
| FormSubmissionPage | `form-submissions/{submission}` |
| UsersPage | `users` |
| AuditLogPage | `audit-log` |
| AuditActivityPage | `audit-log/{activity}` |

`MediaLibraryPage` (`src/Admin/Pages/MediaLibraryPage.php`) extends `Tbtop\Admin\Pages\MediaLibraryPage` from the `tbtop/admin` dependency and adds no path of its own — its route lives in that package, not here.

## 4. Inertia props

Source of truth: `src/Frontend/Http/Middleware/ShareCmsProps.php` for shared props; `src/Frontend/Http/Controllers/PageController.php` and `src/Frontend/Render/PageSpecAssembler.php` for page props.

**Shared `cms` prop** (`ShareCmsProps::handle`, `Inertia::share('cms', ...)`):

| Field | Type | Meaning |
|---|---|---|
| `settings` | `array<string,mixed>` | `Settings::forFrontend()` — public settings keyed dotted, media keys resolved to URLs, secrets excluded |
| `locale` | `string` | current app locale |
| `defaultLocale` | `string` | `Languages::defaultLocale()` |
| `languages` | `list<{locale:string,name:string,is_default:bool}>` | frontend-active languages (`Languages::frontendActive()`) |
| `debug` | `bool` | `config('app.debug')` — there is no `tbtop-cms.*` key for it. The stock `CmsPage` passes `showErrors={isPreview \|\| cms.debug}` to `CmsRenderer`, which only gates a developer `console.error` line for a block the client could not draw — the page looks the same either way |
| `layout` | `array<string,list<ResolvedBlock>>` | resolved header/footer slot blocks, cached per locale under key `layout:{locale}` via `CmsCache::remember`; `HandlesFormSubmission` blocks get `action` bound per request against the current page (`CurrentPage`) or the home page |

Also shared at the top level (not under `cms`): `errors` — session validation error bag, first message per field.

**Page props** (`PageController::props`, rendered via `Inertia::render($component, ...)`; `$component` defaults to `config('tbtop-cms.render.component')`, `'Cms/Page'`):

| Field | Type | Meaning |
|---|---|---|
| `page.id` | `string\|int` | page key |
| `page.type` | `string` | page type key |
| `page.status` | `string` | `PageStatus` enum value |
| `page.subtype` | `string\|null` | subtype key, null for a page of a base type |
| `page.name` | `string\|null` | translated name — this locale, else the default locale, else `null`; a blank (`''`, whitespace) translation counts as missing (`PageTranslation::text()`) |
| `page.title` | `string\|null` | translated title, same fallback |
| `page.heading` | `string\|null` | translated heading, same fallback |
| `page.summary` | `string\|null` | translated summary, same fallback |
| `page.body` | `string` | the Body (General tab) rendered to HTML via `RichtextHtmlRenderer`, active locale falling back to the default locale (`PageTranslation`); `''` when empty |
| `page.image` | `string\|null` | resolved media URL (`MediaUrlResolver`) |
| `page.fields` | `object` | the page type's resolved type fields (`resolveFields()`), keyed by field name; always a JSON object, `{}` when there are none; typed per type and subtype in `pages.generated.ts` (pages.md, "Page prop types") |
| `render` | `{root:string, elements:array<string,{type:string,props:object,children?:list<string>}>}` | page spec from `PageSpecAssembler::assemble()` — flat element map keyed by block id; `root` is a synthetic element (`type` = `config('tbtop-cms.render.root_kind')`, default `Fragment`) whose `children` is the ordered block-id list |
| `seo` | `array<string,mixed>` | `SeoResolver::resolve()` output (title, description, canonical, og*, article*, twitterCard, alternates, xDefault, noindex, ...) — full shape owned by seo.md. The fallback error page (no published DB error page) carries the same key set, empty (`FallbackProps`) |
| `breadcrumbs` | `list<{label:string,path:string,url:string}>` | `Breadcrumbs::forPage()` — the full trail: site root, real ancestry, the page itself last; a non-live ancestor or site root is left out (the page itself never is); `[]` when fewer than two crumbs remain (the home page) and on error pages. Same trail as the JSON-LD `BreadcrumbList` (see seo.md) |
| `path` | `string` | requested path |
| `isPreview` | `bool` | true when the page is not live (draft/scheduled/expired) and the viewer has preview access |

Each `render.elements[id].props` (and root's `props`) serializes as `{}` not `[]` when empty — the block/root has no props.

A content row that cannot render — unregistered kind, missing shared block, shared block of another kind (`BlockRowProblem`), or a `resolve()` that throws — is not in `render` at all: no element, no place in `children`, no JSON-LD node. Only the throw is logged (`block.resolve_failed`); the other three are listed in the admin editor's Problem blocks (blocks.md#problem-blocks). `cms.layout` slots follow the same rule; their `ResolvedBlock` items are `{id, kind, props}`.

**Frontend exports** (`resources/js/cms`, imported via the `@cms` alias):

| Import | Export | Purpose |
|---|---|---|
| `@cms/CmsPage` | default `CmsPage` | The stock `Cms/Page` component: `CmsHead` + preview banner + `CmsRenderer` |
| `@cms/CmsRenderer` | `CmsRenderer({ spec, catalog, registry, showErrors })` | The renderer for pages and layout slots: a block with no component, props failing its schema, or a component that throws renders nothing; `showErrors` only adds a developer `console.error` line — see blocks.md#client-side |
| `@cms/blockErrors` | `dropBrokenBlocks(spec, catalog, registry) → {spec, dropped}`, types `RenderSpec` (= json-render `Spec`), `DroppedBlock`, `CatalogLike` | The pure spec filter behind `CmsRenderer`, for a host with its own render path |
| `@cms/CmsHead` | `CmsHead({ seo })`, types `Seo`, `SeoAlternate` | The SEO `<Head>` block, key-matched with `head.blade.php` — render it from a host-owned `CmsPage.tsx` instead of copying the tags |

## 5. Extension contracts and base classes

Source of truth: the contract/base-class files themselves under `src/`.

| Class/Contract | Path | Methods to implement | Purpose |
|---|---|---|---|
| `Block` (abstract) | `src/Block/Block.php` | Required: `kind(): string`, `form(S $s): list<Field>`. Overridable (have defaults): `label()`, `description()`, `category()`, `placements(): list<string>`, `resolve(array $input, ResolveContext $ctx): array`, `translatableFields()`, `toSchema(array $props, SchemaContext $ctx): ?array` (default `null`) | Base for a block type: schema/form, an optional server-side resolve step, and an optional schema.org node published into the page's `seo.jsonLd` |
| `Placement` | `src/Block/Placement.php` | Constants `LAYOUT`, `PAGES` | Values a `Block::placements()` entry may hold: a page-type key, `Placement::LAYOUT`, or the wildcard `Placement::PAGES` (every page type) |
| `PageTypeInterface` | `src/Page/Contracts/PageTypeInterface.php` | `key()`, `label()`, `allowedChildren(): list<string>`, `requiresParent(): bool`, `maxDepth(): int`, `isSourceBacked(): bool`, `source(Page $page): ?object`, `template(Page $page): ?array`, `schemaTypes(): non-empty-list<string>`, `base(): string`, `fields(S $s): list<Field>`, `resolveFields(array $input, ResolveContext $ctx): array`, `childSubtype(): ?string` | Contract for a page type; `base() !== key()` makes it a subtype (pages.md, "Subtypes and type fields") |
| `BasePageType` (abstract) | `src/Page/Types/BasePageType.php` | Implements `PageTypeInterface` with defaults (`label`=ucfirst(key), `allowedChildren`=[], `requiresParent`=false, `maxDepth`=1, `isSourceBacked`=false, `source`=null, `template`=null, `schemaTypes`=`['WebPage','AboutPage','ContactPage','ItemPage']`, `base`=own key, `childSubtype`=null, `fields`=[]); a subclass need only override `key()` plus whatever defaults don't fit | Convenience base for page types |
| `SchemaTypeInterface` | `src/Frontend/Seo/Schema/SchemaTypeInterface.php` | `key(): string`, `label(): string`, `openGraph(SchemaContext $ctx): OpenGraph`, `build(SchemaContext $ctx): list<array<string,mixed>>` | Contract for a schema.org node type selectable via `page.seo.schema.type` |
| `SchemaType` (abstract) | `src/Frontend/Seo/Schema/SchemaType.php` | Required: `key()`, `build(SchemaContext $ctx): list<array<string,mixed>>`. Overridable (have defaults): `label()` (`Str::headline($this->key())`), `openGraph()` (`OpenGraph::website()`) | Convenience base for a host schema type — extend this, not `SchemaTypeInterface` directly |
| `SchemaContextFactory` | `src/Frontend/Seo/Schema/SchemaContextFactory.php` | `make(Page $page, string $locale, ?object $source = null): SchemaContext` — one og-image media lookup per call, its `alt`/`mime`/`width`/`height` ride on the context | Builds the `SchemaContext` a page's schema node(s) and its blocks' `toSchema()` share; constructible with a purpose-built `Settings` the same way `SeoResolver` is |
| `HandlesFormSubmission` (interface, opt-in on a `Block`) | `src/Block/HandlesFormSubmission.php` | `submitRules(array $blockData, SubmitContext $ctx): array`, `handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void` | Server-side validation and handling for anonymous form submits |
| `HasForm` (trait, opt-in on a `HandlesFormSubmission` block) | `src/Form/HasForm.php` | `formName(): string` (default `kind()`) | Registers the form by name in `Forms` (`Cms::forms()`), so a template posts to it from any page |
| `CmsAdmin` | `src/Admin/Contracts/CmsAdmin.php` | `canAccessCmsAdmin(): bool` | Host's admin-model gate for panel access |
| `Channel` (Notifications) | `src/Notifications/Contracts/Channel.php` | `key(): string`, `label(): string`, `format(): Format`, `settings(S $s): list<Field\|Node\|TextBlock>`, `supports(Recipient $to): bool`, `send(Rendered $message, Recipient $to, Settings $settings): void` | A delivery channel (mail/telegram/bell/sms) |
| `TemplateStore` | `src/Notifications/Contracts/TemplateStore.php` | `override(string $type, Format $format, string $locale): ?string` | Merchant-editable template lookup; package ships `DatabaseTemplateStore` (backed by `tbtop_notification_templates`, strict locale match, no fallback) |
| `HealthCheck` | `src/Health/HealthCheck.php` | `id(): string`, `run(): HealthCheckResult` | One diagnostic check; must not throw |
| `LogsCmsActivity` (trait) | `src/Audit/Concerns/LogsCmsActivity.php` | Required: `auditFields(): list<string>`, `auditLabel(): string`. Overridable: `auditMarkerFields()`, `auditBlockFields()` (default `[]`) | Wires Spatie `LogsActivity` plus CMS audit enrichment onto a model |
| `TracksAuthors` (trait) | `src/Support/Concerns/TracksAuthors.php` | None — just `use` it | Auto-stamps `created_by`/`updated_by` from `CurrentAdmin::user()` on model boot hooks; requires nullable, FK-less `created_by`/`updated_by` columns (see §9's user-key column shape) |

**Static registries**

| Registry | Path | Method signature | Purpose |
|---|---|---|---|
| `BlockRegistry` | `src/Block/BlockRegistry.php` | `static extend(callable(): list<class-string> $extension): void`; `forPlacement(string $placement): array<string, Block>`; `forPlacements(list<string> $placements): array<string, Block>` | Register block classes (must run before the registry's first `get()`/`has()`/`all()`/`kinds()`/`forPlacement()` call); `forPlacement()` filters `all()` to blocks offered at that placement, `forPlacements()` is the union over several (a page's type and subtype) |
| `PageTypeRegistry` | `src/Page/PageTypeRegistry.php` | `static extend(callable(): list<class-string> $extension): void`; `subtypesOf(string $base): list<PageTypeInterface>`; `forPage(Page $page): list<PageTypeInterface>` (the page's type, then its subtype, unregistered ones skipped) | Register page-type classes; same before-boot timing rule |
| `SchemaTypeRegistry` | `src/Frontend/Seo/Schema/SchemaTypeRegistry.php` | `static extend(callable(): list<class-string> $extension): void` | Register `SchemaTypeInterface` classes; same before-boot timing rule; also seeded from `config('tbtop-cms.seo.schema_types')` |
| `RobotsRegistry` | `src/Frontend/Seo/RobotsRegistry.php` | `static extend(callable(): list<string> $extension): void` | Register paths robots.txt disallows. Not boot-latched — re-read on every render, so a late `extend()` still lands |
| `Notifications` | `src/Notifications/Notifications.php` (delegates to `NotificationRegistry`) | `static type(TypeDefinition $type): void`; `static channel(string $channel): void` (`$channel` a `class-string<Channel>`) | Register a notification type / channel class. A duplicate `type()` key or an unregistered `channel()` throws `InvalidArgumentException` |

## 6. Events a host may listen to

| Event | Path | Fired when | Payload |
|---|---|---|---|
| `PageSaved` | `src/Page/Events/PageSaved.php` | `PageObserver` (`created()`/`updated()`), `DB::afterCommit`, on every real page write (a write touching only `lock_version` is skipped) | `Page $page`, `bool $wasCreated` |
| `PagePublished` | `src/Page/Events/PagePublished.php` | `PageObserver::dispatchPublishTransition()` when status transitions into `Published` (on create, or on update when it wasn't Published before) | `Page $page` |
| `PageUnpublished` | `src/Page/Events/PageUnpublished.php` | `PageObserver::dispatchPublishTransition()` when status transitions out of `Published` on update | `Page $page` |
| `PageDeleted` | `src/Page/Events/PageDeleted.php` | `PageEditPage` soft-delete action (`forced: false`), `PagesPage` force-delete action (`forced: true`) | `Page $page`, `bool $forced` |
| `PageRestored` | `src/Page/Events/PageRestored.php` | `PagesPage` restore action | `Page $page` |
| `LayoutSaved` | `src/Layout/Events/LayoutSaved.php` | `LayoutForm::save()` after `Layout::updateOrCreate` | `Layout $layout` |
| `SharedBlockSaved` | `src/SharedBlock/Events/SharedBlockSaved.php` | `SharedBlockForm::create()` after the write; `update()` via `DB::afterCommit`, after the save transaction commits | `SharedBlock $block` |
| `SharedBlockDeleted` | `src/SharedBlock/Events/SharedBlockDeleted.php` | `SharedBlocksPage` delete action, after `delete()` (offered only while nothing places the block, re-checked server-side) | `SharedBlock $block` |
| `LanguagesChanged` | `src/Language/Events/LanguagesChanged.php` | `LanguageObserver`, on any create/update/soft-delete/restore/force-delete of a `Language` | none |
| `SettingsSaved` | `src/Settings/Events/SettingsSaved.php` | `SettingsForm::apply()`, once per form submission that wrote at least one key | `array $keys` (dotted store keys written) |
| `SubmissionReceived` | `src/Form/Events/SubmissionReceived.php` | `FormSubmitController::__invoke()`, after validation and storage, before `handleSubmit()` | `?FormSubmission $submission`, `array $payload`, `Page $page`, `string $blockId`, `string $locale` |
| `PageNotFound` | `src/NotFound/Events/PageNotFound.php` | `PageController`, past redirect resolution (a redirect hit never reaches here) | `string $path`, `string $locale`, `?string $referer`, `?string $userAgent` |
| `CmsAccessGranted` | `src/Admin/Events/CmsAccessGranted.php` | `AdminAccess::grant()`, when a `CmsUserProfile` row is created for a user | `int\|string $userId` |
| `CmsAccessRevoked` | `src/Admin/Events/CmsAccessRevoked.php` | `AdminAccess::revoke()`, when a non-owner `CmsUserProfile` row is deleted | `int\|string $userId` |
| `NotificationSent` | `src/Notifications/Events/NotificationSent.php` | `NotificationChannelDriver`, after one channel delivers to one recipient | `string $type`, `string $channel`, `Recipient $recipient` |
| `NotificationFailed` | `src/Notifications/Events/NotificationFailed.php` | same call site, on a caught channel-send exception, and via `NotificationChannelDriver::recordFailure()` when the pair's template fails to render (other pairs still run) | `string $type`, `string $channel`, `Recipient $recipient`, `string $error`, `DeliveryStage $stage = DeliveryStage::Send` (`Render` for a render failure) |

Caveat: `PagePublished`/`PageUnpublished` fire on the DB status transition at save time, not on scheduled go-live/go-dark (`published_at`/`unpublish_at`) — the scheduled-transition case is not wired to these events yet.

## 7. Settings keys

Source of truth: `src/Settings/Settings.php` (store + `forFrontend()` rules) and `src/Settings/Support/SettingCodec.php` (encode/decode), cross-checked against `config('tbtop-cms.settings.secret')`. A key is secret iff listed there (`mail.password`, `notifications.telegram.bot_token`) — secret values are always encrypted at rest and excluded from `forFrontend()`. A key is frontend-exposed iff it starts with `site.` or `seo.` and is not secret; `site.logo`, `site.favicon`, `seo.default_og_image`, `seo.identity.logo` are additionally resolved from a stored media id to a public URL.

| Key | Edited on | Secret? | Frontend-exposed? |
|---|---|---|---|
| `site.name` | `SiteSettingsPage` | no | yes |
| `site.logo` | `SiteSettingsPage` | no | yes (media URL) |
| `site.favicon` | `SiteSettingsPage` | no | yes (media URL) |
| `site.contacts.phones` (`[{number}]`) | `SiteSettingsPage` | no | yes — public contact data; read via `Tbtop\Cms\Settings\SiteContacts`, first entry is the identity JSON-LD `telephone` (the only source) |
| `site.contacts.emails` (`[{address}]`) | `SiteSettingsPage` | no | yes — same, the first entry is the identity `email` (the only source) |
| `site.contacts.socials` (`[{label, name, href}]`) | `SiteSettingsPage` | no | yes — same, the `href`s are the identity `sameAs` (the only source) |
| `seo.title_template` | `SeoSettingsPage` | no | yes |
| `seo.title_separator` | `SeoSettingsPage` | no | yes |
| `seo.title_suffix` | none — legacy, still mapped by `SeoSettingsPage` with no field; a stored value keeps working | no | yes |
| `seo.description_template` | `SeoSettingsPage` | no | yes |
| `seo.default_description` | `SeoSettingsPage` | no | yes |
| `seo.default_og_image` | `SeoSettingsPage` | no | yes (media URL) |
| `seo.indexing_enabled` | `SeoSettingsPage` | no | yes |
| `seo.robots_extra` | `SeoSettingsPage` | no | yes |
| `seo.sitemap_per_chunk` | `SeoSettingsPage` | no | yes |
| `seo.gtm_container_id` | `SeoSettingsPage` | no | yes |
| `seo.verification.google` | `SeoSettingsPage` | no | yes |
| `seo.verification.bing` | `SeoSettingsPage` | no | yes |
| `seo.identity.type` | `SeoSettingsPage` | no | yes |
| `seo.identity.name` | none — legacy, still mapped by `SeoSettingsPage` with no field; falls back to `site.name` | no | yes |
| `seo.identity.logo` | none — legacy, still mapped by `SeoSettingsPage` with no field; falls back to `site.logo` | no | yes (media URL) |
| `seo.identity.legal_name` | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.address.street` (translatable locale map) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.address.locality` (translatable locale map) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.address.region` (translatable locale map) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.address.postal_code` | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.address.country` (ISO 3166-1 alpha-2) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.subtype` (`local_business` only) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.subtype_custom` (`local_business` only, used when the subtype is `other`) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.opening_hours` (`local_business` only; list of `{days, opens, closes}`) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.geo.latitude` (`local_business` only) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.identity.geo.longitude` (`local_business` only) | `SeoSettingsPage` | no | yes — public business data, also published in the identity JSON-LD |
| `seo.twitter_card` | `SeoSettingsPage` | no | yes |
| `seo.not_found.enabled` | `SeoSettingsPage` | no | yes |
| `seo.not_found.retention_days` | `SeoSettingsPage` | no | yes |
| `seo.type_templates.{type}.title` / `seo.type_templates.{type}.description` (per registered page type) | `SeoSettingsPage`, built dynamically from `PageTypeRegistry` via `SeoTemplates::key()` | no | yes |
| `notifications.mail.recipients` | `NotificationSettingsPage` | no | no |
| `mail.host` | `NotificationSettingsPage` | no | no |
| `mail.port` | `NotificationSettingsPage` | no | no |
| `mail.username` | `NotificationSettingsPage` | no | no |
| `mail.password` | `NotificationSettingsPage` | yes | no |
| `mail.encryption` | `NotificationSettingsPage` | no | no |
| `mail.from_address` | `NotificationSettingsPage` | no | no |
| `mail.from_name` | `NotificationSettingsPage` | no | no |
| `notifications.telegram.bot_token` | `NotificationSettingsPage` | yes | no |
| `notifications.telegram.chat_ids` | `NotificationSettingsPage` | no | no |
| `notifications.{typeKey}.channels` (one row per registered type, e.g. `notifications.form.submission.channels`) | `NotificationSettingsPage`, one checkbox-list field per type generated from `NotificationRegistry` | no | no |

`notifications.mail.enabled` and `notifications.telegram.enabled` are legacy keys removed from `NotificationSettingsPage`'s UI and no longer read anywhere in `src/`; a pre-existing row in `tbtop_settings` is simply unused. `notifications.{typeKey}.channels` is the per-notification-type channel list `Notifications::enabledChannels()` reads — merchant-editable via the settings page's per-type checkbox list.

## 8. Section abilities (`CmsAbilities::ALL`)

Source: `src/Admin/Support/CmsAbilities.php`. Gates are host-answered only when `config('tbtop-cms.roles')` is truthy; `CmsAbilities::for()` returns `null` (no check) otherwise, leaving the section open to any admin.

| Ability | Declaring admin page(s) |
|---|---|
| `tbtop-cms.pages` | `src/Admin/Pages/PagesPage.php`, `src/Admin/Pages/PageEditPage.php` |
| `tbtop-cms.media` | `src/Admin/Pages/MediaLibraryPage.php` |
| `tbtop-cms.submissions` | `src/Admin/Pages/FormSubmissionsPage.php`, `src/Admin/Pages/FormSubmissionPage.php` |
| `tbtop-cms.redirects` | `src/Admin/Pages/RedirectsPage.php`, `src/Admin/Pages/NotFoundLogPage.php`, `src/Admin/Pages/SeoReportPage.php` |
| `tbtop-cms.settings` | `src/Admin/Pages/SettingsPageBase.php` (inherited by `SiteSettingsPage`, `SeoSettingsPage`, `NotificationSettingsPage`), `src/Admin/Pages/SiteHealthPage.php` |
| `tbtop-cms.design` | `src/Admin/Pages/BlocksPage.php`, `src/Admin/Pages/LayoutPage.php`, `src/Admin/Pages/LayoutEditPage.php`, `src/Admin/Pages/SharedBlocksPage.php`, `src/Admin/Pages/SharedBlockEditPage.php` |
| `tbtop-cms.languages` | `src/Admin/Pages/LanguagesPage.php` |
| `tbtop-cms.users` | `src/Admin/Pages/UsersPage.php` |
| `tbtop-cms.audit` | `src/Admin/Pages/AuditLogPage.php`, `src/Admin/Pages/AuditActivityPage.php` |
| `tbtop-cms.admins` (`CmsAbilities::ADMINS`, not in `ALL`) | No page. `src/Admin/Pages/UsersPage.php` reads it (roles on only) to make its holder an admin manager, who may edit and revoke other admins. **Granting it gives control of other admin accounts.** |

The media API routes (`tbtop.cms.media.*`, registered by tbtop/admin) are gated by `src/Admin/Http/Middleware/AuthorizeMediaApi.php` when roles are on: reading and adding needs any of `tbtop-cms.media`, `.pages`, `.settings`, `.design`; changing, replacing and deleting files and folders, and any unmapped media route, need `tbtop-cms.media` (map: `AuthorizeMediaApi::ROUTES`; see wiring.md#admin-access-and-roles).

`DashboardPage` checks `Gate::allows('tbtop-cms.submissions')` to conditionally show a widget but declares no page-level ability itself. `PageEditPage` (under `tbtop-cms.pages`) shows and accepts a block's "Make shared" action (`pageBlockShare_{id}`) only when `tbtop-cms.design` also allows — it creates a shared block; a hand-made post without it is a 403. `PageEditPage` and `LayoutEditPage` show **Problem blocks (N)** (`pageProblemBlocks` / `layoutProblemBlocks`, a modal with one `…ProblemDelete_{rowId}` action per row) when the container has rows the site skips, under the page's own ability; lang keys `tbtop-cms::pages.problem_blocks.{action,title,description,reasons.{unregistered,shared_missing,shared_kind_mismatch},delete,delete_title,delete_confirm,deleted}` — see blocks.md#problem-blocks. `ProfilePage` and `LoginPage` declare none.

## 9. Database tables

Source: `database/migrations/`. CREATE migrations target `tbtop_`-prefixed names except `notifications` and `activity_log` (third-party tables the CMS creates for its own use). DROP migrations (`tbtop_menus`, `tbtop_templates`) removed features and are not listed.

| Table | Owning concept | `tbtop_` prefixed? | Notes |
|---|---|---|---|
| `tbtop_languages` | `src/Language/Models/Language.php` | Yes | |
| `tbtop_pages` | `src/Page/Models/Page.php` | Yes | `created_by`/`updated_by` via `UserKeyColumn::define`; gained `body`/`excerpt`, `image`, `source_id`, scheduling columns, a starter-blocks flag, a nullable unique `error_class` column (`ErrorClass` enum, migration `2026_09_15_000000_add_error_class_to_pages_table.php`), and `subtype` (nullable, indexed) + `fields` (JSON, type fields' values; migration `2026_10_06_000000_add_subtype_and_fields_to_pages_table.php`, which also moves a subtype key out of `type` and strips `owner` from content rows) over later ALTER migrations |
| `tbtop_page_slugs` | `src/Page/Models/PageSlug.php` | Yes | |
| `tbtop_user_profiles` | `src/Admin/Models/CmsUserProfile.php` | Yes | `user_id` via `UserKeyColumn::define` (unique); gained a `user_id` FK, then `role` (added, later dropped), then `is_owner` |
| `tbtop_redirects` | `src/Redirect/Models/Redirect.php` | Yes | `created_by`/`updated_by` via `UserKeyColumn::define` |
| `tbtop_settings` | `src/Settings/Models/Setting.php` | Yes | |
| `tbtop_form_submissions` | `src/Form/Models/FormSubmission.php` | Yes | gained `lead_status` in a later migration |
| `tbtop_layouts` | `src/Layout/Models/Layout.php` | Yes | |
| `tbtop_shared_blocks` | `src/SharedBlock/Models/SharedBlock.php` | Yes | `name`, `kind`, json `data` (same shape as a page content row's `data`), `lock_version` (bumped in SQL on every update; an edit form save claims it atomically, so a stale or concurrent save is rejected); page content rows point at it via a top-level `shared` key |
| `tbtop_page_revisions` | `src/Page/Models/PageRevision.php` | Yes | `user_id` via `UserKeyColumn::define` (nullable) |
| `tbtop_page_refs` | `src/Page/Refs/PageRefIndex.php` (no model) | Yes | Migration `2026_10_07_000002_create_page_refs_table.php`; `page_id` (FK, cascade on delete), `field`, `target_id` (no FK), `position`; indexes `(target_id, field)` and `page_id`. Derived: rewritten per page on save, `cms:page-refs:rebuild` recomputes it |
| `tbtop_form_submission_comments` | `src/Form/Models/FormSubmissionComment.php` | Yes | `user_id` via `UserKeyColumn::define` |
| `notifications` | Laravel's built-in notifications table, used by `src/Notifications/*` | No | UUID primary key, standard Laravel shape |
| `tbtop_not_found_hits` | `src/NotFound/Models/NotFoundHit.php` | Yes | |
| `activity_log` (name/connection from the resolved `activitylog.activity_model`, `spatie/laravel-activitylog` v5) | `spatie/laravel-activitylog`, wired by `src/Audit/Concerns/LogsCmsActivity.php` | No | package publishes spatie's v5 migration itself (`attribute_changes` column, no `batch_uuid`); guarded no-op if the host already has the table |
| `tbtop_notification_log` | `src/Notifications/Models/NotificationLog.php` | Yes | |
| `tbtop_seo_audit_snapshots` | `src/Seo/Models/SeoAuditSnapshot.php` | Yes | One row per calendar day (`captured_on`, unique); written by `cms:seo:snapshot` and on every `SeoReportPage` load |
| `tbtop_notification_templates` | `src/Notifications/Models/NotificationTemplate.php` | Yes | Migration `2026_09_01_000000_create_notification_templates_table.php`; unique on `(type, format, locale)`; merchant-edited overrides, owner-only, no audit log |

**User-key column shape** — `src/Support/UserKeyColumn.php::define(Blueprint $table, string $column)` declares a `user_id`/`created_by`/`updated_by`-shaped column matching the host's configured user model (`tbtop-cms.admin.model`) primary-key type: `ulid()` if the model uses `HasUlids`, `uuid()` if `HasUuids`, `unsignedBigInteger()` for a plain auto-incrementing int PK (also the fallback if the model class can't be resolved). Throws `RuntimeException` for any other key shape — lets int-PK and UUID/ULID-PK hosts both migrate and write successfully.

## 10. Facade/service API surface

| Class | Method | Purpose |
|---|---|---|
| `Tbtop\Cms\Cms` (facade `Tbtop\Cms\Facades\Cms`) | `frontendRoutes(): void` | Registers all CMS frontend routes — call from a `web`-middleware route file |
| | `resolve(string $path, ?string $locale = null): ?Page` | Resolves a path to a `Page` for the given/current locale |
| | `url(Page $page, ?string $locale = null): string` | Builds the absolute frontend URL for a page in a locale |
| | `breadcrumbs(Page $page, ?string $locale = null): array` | Returns the breadcrumb trail for a page — home › ancestors › page, non-live ancestors and a non-live home left out, `[]` for home or when fewer than two crumbs remain |
| | `errorResponse(Request $request, int $status): Response` | Always returns a response for the status's error class: seeded DB page → chrome fallback (`render: []`) → (5xx only) static Blade view if rendering throws |
| | `forms(): Forms` | The `HasForm` forms by name (blocks.md "Forms reachable from any page") |
| | `referencedBy(?Page $page, string $field, ?string $from = null, ?string $locale = null): list<Page>` | Live pages whose page-type `pages` field `$field` picks `$page` (only type/subtype `$from` when given), by `sort_order` then name; `[]` for a null page (pages.md "Page references") |
| | `referencedByLinks(?Page $page, string $field, ?string $from = null, ?string $locale = null): list<{id, name, url}>` | `referencedBy()` as the `{id, name, url}` list a `pages` field resolves to |
| `Tbtop\Cms\Form\Forms` | `props(string $name, Page $page, ?string $locale = null): array` | `{action, honeypotField?}` for a template-rendered form posting from `$page`; also `all()`, `has()`, `get()`, `action()` |
| `Tbtop\Cms\Settings\Settings` | `get(string $key, mixed $default = null): mixed` | Reads one decoded settings value (decrypts if the key is secret) |
| | `set(string $key, mixed $value): void` | Writes/upserts one setting, bumps the content cache |
| | `forget(string $key): void` | Deletes one setting, bumps the content cache |
| | `forFrontend(): array` | All non-secret `site.*`/`seo.*` settings, media-id keys resolved to public URLs |
| | `isSecret(string $key): bool` | Whether a key is in `tbtop-cms.settings.secret` |
| `Tbtop\Cms\Cache\CmsCache` | `remember(string $key, Closure $fn): mixed` | Cache-or-compute under the current content-cache version |
| | `rememberLive(string $key, Closure $fn): mixed` | Like `remember()`, TTL capped to the next page schedule transition |
| | `rememberWithTtl(string $key, Closure $ttlFor, Closure $fn): mixed` | Like `remember()`, TTL computed from the produced value |
| | `defaultTtl(): int` | Returns the configured cache TTL |
| | `bump(): void` | Invalidates every previously cached versioned key |
| `Tbtop\Cms\Mail\CmsMailer` | `send(Mailable $mailable, array\|string $to): void` | Sends mail via an on-demand mailer built from admin `mail.*` settings, or the host's default mailer if `mail.host` is unset |
| | `transportConfig(): array` | Builds the Laravel mail transport config array from `mail.*` settings |
| `Tbtop\Cms\Richtext\RichtextHtmlRenderer` | `render(mixed $value): string` | Renders a Lexical-shaped richtext value to HTML; a legacy HTML string (one the admin editor reads as HTML) is rebuilt through the same node renderer, never passed through; other plain strings wrap as one paragraph; empty/unrecognized input renders `''` |
| `Tbtop\Cms\Link\LinkResolver` | `resolve(mixed $value, string $locale): ?array` | Resolves a `link` field value to `{href, type, label}`; `null` for malformed input, a non-live page, or a page with no slug in that locale. Reached from a block via `$ctx->link()` |
| `Tbtop\Cms\Language\Languages` | `all(): array` | All configured languages (cached) |
| | `default(): ?array` | The language row flagged `is_default` |
| | `defaultLocale(): string` | Locale code of the default language |
| | `current(): ?array` | The currently-set language, or `default()` if none set |
| | `setCurrent(string $locale): void` | Sets the active locale for this request; throws if not frontend-active |
| | `frontendActive(): array` | Languages with `is_frontend_active` |
| | `adminActive(): array` | Languages with `is_admin_active` |
| | `setDefault(string $locale): void` | Transactionally reassigns the default language |
| | `refresh(): void` | Forces a re-read from the database, bypassing the cross-request cache |

## 11. Built-in blocks and page types

**Blocks** — `src/Block/BlockRegistry.php::boot()` registers classes from `config('tbtop-cms.blocks.types')`, then the concrete `Block` subclasses found under `config('tbtop-cms.blocks.discover')` folders (both empty by default), plus classes contributed via `BlockRegistry::extend()`. `Hero` and `ContactForm` are reference/example blocks, not auto-registered — a host opts in via config or `extend()`.

| Kind | Class path | Purpose |
|---|---|---|
| `Hero` | `src/Block/Blocks/HeroBlock.php` | Full-width intro section: headline, optional subtitle, image, CTA button |
| `ContactForm` | `src/Block/Blocks/ContactForm.php` | Reference anonymous contact form (name/email/message fields, editable copy); `handleSubmit()` is an intentional no-op — notification fan-out happens off `SubmissionReceived` via `SubmissionNotifier` |

**Page types** — `src/Page/PageTypeRegistry.php::boot()` always registers `PageType`, `CategoryType`, `ArticleType` (hardcoded, not config-gated), then adds `config('tbtop-cms.pages.types')` and `PageTypeRegistry::extend()` contributions. The three built-ins are resolved through the container (`app($class)`), so a host overrides one by binding a subclass in its service provider's `register()` — e.g. to widen `schemaTypes()`; see recipes.md → *Different schema for some articles*. Registering a second class under an existing key via config still throws `already registered`.

| Key | Class path | Purpose |
|---|---|---|
| `page` | `src/Page/Types/PageType.php` | Plain page type, no overrides beyond `BasePageType` defaults |
| `category` | `src/Page/Types/CategoryType.php` | Allows only `category`/`article` children; `maxDepth(): 3` |
| `article` | `src/Page/Types/ArticleType.php` | `requiresParent(): true` |
