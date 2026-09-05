# feat(audit): port to spatie/laravel-activitylog v5

touches: activity logging, audit context, audit log admin pages, activity log table, redirect writer, activity log health check

> ⚠️ **Migration** `spatie/laravel-activitylog` bumped from `^4.12.3` to `^5.0` (resolved 5.1.0). A v4 host upgrading alongside this package must run its own data migration per spatie's `UPGRADING.md`: add `attribute_changes` (json, nullable), drop `batch_uuid`, and move each row's `properties.attributes`/`properties.old` into `attribute_changes`. This package's own migration (`database/migrations/2026_08_18_000000_create_activity_log_table.php`) only creates the table when it is missing — it never alters an existing v4 table, so a host with a pre-existing `activity_log` table must run that migration itself before upgrading past this version.
> ⚠️ **Contract** `properties.batch` replaces spatie's removed `batch_uuid` column/`LogBatch` facade. `AuditContext::batch()` now opens a package-level UUID (nested calls reuse the outermost one) and both `AuditContext::enrich()` and `AuditContext::log()` stamp it onto `properties.batch` when one is open. A host reading `Activity::batch_uuid` to group a bulk operation must read `properties->get('batch')` instead.
> ⚠️ **Contract** `beforeActivityLogged(Activity $activity, string $eventName)` replaces `tapActivity(...)` as the hook spatie calls before saving an activity (same signature, same call site — spatie now calls it via `method_exists($subject, 'beforeActivityLogged')`). Any host model that composes `LogsCmsActivity` and overrides `tapActivity()` directly must rename the override; the trait's own hook is already renamed.
> ⚠️ **Contract** `activitylog.table_name` / `activitylog.database_connection` config keys are gone in v5 — a host that redirected the activity log table via those keys must instead point `activitylog.activity_model` at a custom `Activity` subclass with `$table`/`$connection` set. The CMS's migration and `ActivityLogTableCheck` health check now resolve the table/connection off that model instead of the removed config keys.

Tracked model changes (`attributes`/`old`) no longer live under `properties` —
spatie v5 writes them to a new `attribute_changes` JSON column, leaving
`properties` for custom data only. `AuditContext::enrich()` now reads/writes
`attribute_changes` for the tracked diff (stripping marker fields and
summarizing block fields there) while `subject_label`, `changed_fields`,
`context`, and the new `batch` key stay on `properties`; `AuditContext::log()`
routes its `$changes` argument through `withChanges()` instead of folding it
into `withProperties()`. `LogsCmsActivity::dontSubmitEmptyLogs()` →
`dontLogEmptyChanges()`, and `RedirectWriter`'s `withoutLogs()` →
`withoutLogging()`, both pure renames with the trait/service moved to their v5
sub-namespaces (`Spatie\Activitylog\Models\Concerns\LogsActivity`,
`Spatie\Activitylog\Support\{LogOptions,ActivityLogger}`).

Admin audit pages were updated to match: `AuditActivityPage`'s before/after
sections now read `attribute_changes.old`/`attribute_changes.attributes`
instead of `properties.old`/`properties.attributes`; `AuditLogPage`'s table
columns (`properties.subject_label`, `properties.changed_fields`) were
already correct and needed no change. The `activitylog:clean` command's
signature (`{log?} {--days=} {--force}`) is unchanged in v5, so
`CmsServiceProvider`'s scheduled `activitylog:clean tbtop-cms --days=... --force`
needed no change either.

**Tests rewritten** (behavior preserved, only the storage location of
assertions changed): `tests/Audit/LogsCmsActivityTest.php`'s block-summary
assertion moved from `properties.attributes.content` to
`attribute_changes.attributes.content` (plus a new regression test asserting
`properties` never regains `attributes`/`old` keys); `tests/Audit/StarterBlockAuditTest.php`'s
one-batch assertion moved from `Activity::batch_uuid` to
`data_get($activity->properties, 'batch')`; `tests/Admin/AuditLogTest.php`'s
secret-redaction assertion moved from `properties.attributes.value` to
`attribute_changes.attributes.value`. A new `tests/Health/Checks/ActivityLogTableCheckTest.php`
asserts the health check accepts the v5 schema the package's own migration
creates.
