# refactor(admin): row actions read the record key via `$ctx->key()`

touches: row actions on Pages, Languages, Redirects, Form submissions, 404 log,
Users and the shared-block usage table

Row action handlers now take the clicked record's key from `ActionCtx::key()`
(tbtop/admin ^0.6) instead of `$ctx->row['id']`. `key()` reads `_key`, which
every table row carries as the raw model key, so a handler keeps finding its
record if an `id` column is ever formatted or a model is keyed otherwise; rows
without `_key` fall back to `id`, so behaviour is unchanged today.

Tests: existing page action suites (no behaviour change).

**Read by eye:**
- `src/Admin/Pages/*Page.php` (one-line swaps)
