# fix(admin): a users-table row id must be a key of the user model

touches: Users page actions (grant, revoke, edit load, edit save)

Security: on MySQL a tampered row id such as `1-2` was compared with the
integer `user_id` / primary key numerically, i.e. as `1`. It passed the
"not yourself" guard (which compares as strings) and then reached the actor's
own profile and user row: an admin manager could revoke or edit themselves.
The owner stayed protected. For an int-keyed user model a row id that is not
all digits now answers 404 before any check runs.

Unchanged: uuid/ulid user keys are passed through as strings; numeric ids and
every guard behave as before.

Tests: `tests/Admin/UsersPageTest.php` (row id that is not a key).

**Read by eye:**
- `src/Admin/Pages/UsersPage.php`
