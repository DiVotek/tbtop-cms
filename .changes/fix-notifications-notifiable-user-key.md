# fix(notifications): notifications.notifiable_id follows the host user key

touches: bell channel delivery, `notifications` table (package-created or
host-owned)

On a host whose user model uses `HasUlids` or `HasUuids`, no bell
notification was ever stored on MySQL. The package created `notifications`
with `morphs('notifiable')`, so `notifiable_id` was `bigint unsigned`, and
strict MySQL refused every user key with "Data truncated for column
'notifiable_id'". The pipeline caught the error and logged each delivery as
`bell` / `failed` in `tbtop_notification_log`, so staff saw no bell
notifications. SQLite stores the string in the integer column, so the suite
never caught it.

- The create migration types `notifiable_id` with `UserKeyColumn::define`,
  like every other user-key column, and keeps the
  `notifications_notifiable_type_notifiable_id_index` index. A host that chose
  a default morph key type (`Schema::morphUsingUuids()` / `morphUsingUlids()`)
  still gets `morphs()`, which follows that choice.
- New migration `2026_10_22_000000_update_notifications_notifiable_id_match_host_key_type`
  changes an existing integer `notifiable_id` to the host key type (`char(26)`
  for ULIDs, `char(36)` for UUIDs) when the host user key is not an integer.
  It keeps the column's nullability. It does so for a host-owned
  `notifications` table too, since the bell writes host user keys into it.
  It never narrows a string column on an int-key host, does nothing when the
  host chose a default morph key type, and its `down()` is a no-op. Existing
  integer ids stay as numeric strings (`42` → `'42'`).

> ⚠️ **Behavior change** On a HasUlids/HasUuids host that kept the default
> `int` morph key type, `php artisan migrate` alters an integer
> `notifications.notifiable_id` to the user key type, even when the host
> created that table itself.

Unchanged: int-key hosts on the default `int` morph key type (column stays
`bigint unsigned`), hosts that chose a default morph key type (column stays
what `morphs()` makes of it), the table's other columns and the bell payload.

Tests: `tests/Notifications/BellUlidHostTest.php` (bell delivery to a ULID
user; red on MySQL before the fix), `tests/Support/NotifiableKeyColumnMigrationTest.php`
(create and alignment migrations on ULID and int-key hosts, a host default
morph key type, a nullable column, existing integer rows).

**Read by eye:**
- `database/migrations/2026_01_01_000020_create_notifications_table.php`
- `database/migrations/2026_10_22_000000_update_notifications_notifiable_id_match_host_key_type.php`
