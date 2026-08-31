# Upgrading

## Table names now use the `tbtop_` prefix

All package tables have been renamed to a single `tbtop_` prefix, for example
`pages` → `tbtop_pages` and `cms_user_profiles` → `tbtop_user_profiles`. See
`CmsServiceProvider::configurePackage()` for the full migration list and each
`create_*_table` migration for its old/new name.

Existing installs: just run

```bash
php artisan migrate
```

The new `2026_01_01_000018_update_tables_add_tbtop_prefix_table` migration
renames each existing table to its `tbtop_`-prefixed name in place — data is
preserved, no manual steps required. A fresh install has nothing to rename:
the `create_*_table` migrations already create the tables under their new
names directly, so this migration is a no-op there.

Note: `Schema::rename()` does not rename a table's auto-generated index and
constraint names (e.g. an index created as `pages_type_index` stays named
that way after the table becomes `tbtop_pages`). This is left as-is on
upgraded databases — nothing in the package references index names by
string, so it's cosmetic only.

## `BlockRegistry` and `PageTypeRegistry` are now extendable from packages

Both registries gain a static `extend(callable $extension): void`. A package
that ships its own blocks or page types calls it with a callable returning
the classes to register, typically from its service provider's `register()`:

```php
BlockRegistry::extend(fn (): array => [ProductGallery::class, ProductBuyBox::class]);
PageTypeRegistry::extend(fn (): array => [ProductType::class]);
```

The callable is invoked once, lazily, the first time the registry is used
(`get()`/`has()`/`all()`/`kinds()`/`keys()`) — never at `extend()` time — so
provider boot order doesn't matter. Call `extend()` before that first use;
once a registry singleton has booted, a later `extend()` call is stored but
never read. Tests get `BlockRegistry::flushExtensions()` /
`PageTypeRegistry::flushExtensions()` for isolation between cases.

## `BlockContentField` moved to `Admin\Fields\BlockContent`

`Tbtop\Cms\Admin\Pages\Support\BlockContentField` is now
`Tbtop\Cms\Admin\Fields\BlockContent`, a public API for any host that needs
the block-content editor field outside cms's own page/layout forms.
`make()`, `toEnvelope()` and `toRows()` keep their existing behavior;
`make()` gains an optional `$name` parameter (default `'content'`) to mount
the repeater under a different form field. There is no compatibility shim —
update any reference to the old class name.

## Pages can be backed by a source (source-backed page types)

`tbtop_pages` gains two nullable columns, `source_type` and `source_id`
(indexed together), plus three new `PageTypeInterface` methods:

```php
public function sourceType(): ?string;
public function source(Page $page): ?object;
public function template(Page $page): ?array;
```

`Tbtop\Cms\Page\Types\BasePageType` implements all three as `null`, so
`PageType`, `CategoryType`, `ArticleType` and any host type that already
extends it keep working unchanged — the interface growth is backward
compatible. A host type that implements `PageTypeInterface` directly
(without extending `BasePageType`) must add the three methods itself.

A type opts into being source-backed by returning a non-null `sourceType()`
(e.g. `'product'`) and loading its domain object from `(source_type,
source_id)` in `source()`. `PageTypeRegistry` gains `isSourceBacked(string
$key): bool` and `sourceBackedKeys(): list<string>` to query this without a
`Page` instance.

On the frontend, `page.content === null` now means "render the type's
template": `PageController` calls `$type->template($page)` when content is
null, and passes the resolved `source()` through to every block via a new
`ResolveContext::$source` property (`BlockContentResolver::resolve()` gains
a matching `?object $source` parameter, appended last so existing positional
callers are unaffected). A source-backed page whose `source()` resolves to
`null` (the underlying record was deleted or is unpublished) 404s exactly
like an unpublished page.

In the page editor, a source-backed page shows a read-only "Source" card
(type, source_type, source_id, and the source's string form when it is
`Stringable`) instead of the type select, and hides block editing entirely
— `content` is left untouched on save, so it stays `null` and keeps
rendering the template. The create form and the edit sidebar's type select
both exclude source-backed types: creating or retyping one by hand is not
supported, a host that syncs pages from another source owns that.
