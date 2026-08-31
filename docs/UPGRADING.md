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
