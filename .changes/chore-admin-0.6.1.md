# chore(deps): tbtop/admin and @tbtop/inertia-admin 0.6.1

touches: admin tables (pages, form submissions, 404 log), composer and npm
dependencies

`tbtop/admin` 0.6.1 moves `enshrined/svg-sanitize` to ^1.0: 0.22.0 has open
advisories (GHSA-v383-3rw5-q8rf and another `processReferences` issue), and
Composer refuses to install it, which broke `composer install` in CI.

> ⚠️ **Behavior change** Since tbtop/admin 0.6 a table row carries only its
> declared columns (plus the record key). The fields our row actions read were
> undeclared, so they are now declared as columns hidden by default: `path`
> (pages, for the view-on-site link), `status` (form submissions, for retry),
> `has_redirect` and `is_ignored` (404 log, for create-redirect and
> ignore/unignore). They show up in each table's Columns menu.

Unchanged: `$ctx->row['id']` still works (the key ships under its own name);
moving handlers to `$ctx->key()` is left for later.
