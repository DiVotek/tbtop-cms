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
