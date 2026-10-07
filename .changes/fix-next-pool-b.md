# fix(admin): admin manager in Users, media API gated by section, failed retries stay failed, lead_status rollback

touches: Users page, admin panel middleware (media API), submission retry, notifier, lead_status migration down()

> ⚠️ **Contract** Only the admin manager edits or revokes another admin on the Users page: the owner, plus, when `tbtop-cms.roles` is on, holders of the new gate `tbtop-cms.admins` (`CmsAbilities::ADMINS`, not in `CmsAbilities::ALL`). Other admins edit only themselves and rows without CMS access. Refusals: `userEditSave` → `haltModal(tbtop-cms::users.errors.not_allowed)`, `userRevoke` → `notify(tbtop-cms::users.errors.revoke_not_allowed, 'error')`, edit-form loader → 403.
> ⚠️ **Contract** With `tbtop-cms.roles` on, the media API (`tbtop.{panel}.media.*`) answers 403 by action: read/add needs any of `tbtop-cms.media`, `.pages`, `.settings`, `.design`; edit, replace, delete and folder rename/delete need `tbtop-cms.media`; an unmapped media route needs `tbtop-cms.media`.
> ⚠️ **Contract** `SubmissionNotifier::notifyClaimed()` is replaced by `deliver()`, which returns the channel errors and does not release the claim or write `handled_at`.

- **B1.** Any admin could take over another admin's account: `userEditSave`
  only refused edits of the owner, and `row.id` comes from the client. Now
  every action and the edit-form loader check the actor on the server; the
  list hides Edit/Revoke the actor may not use (new hidden `manages_admins`
  column). In `CmsAdmin` mode the list now reads the real `is_owner`, so the
  owner row hides Edit there too.
- **B2.** The media API, registered by tbtop/admin, checked no ability, so an
  admin without `tbtop-cms.media` could delete or replace any file. New
  `AuthorizeMediaApi` middleware in `CmsPanel` maps route names to abilities.
- **B4.** A retry whose `handleSubmit()` threw again still sent the
  notification fan-out and released the row as `Handled`. The retrier now
  stops at a handler error (`Failed`, no notifications, `handled_at` null),
  and releases the claim once with the combined outcome.
- **Rollback.** `migrate:rollback` failed on SQLite at
  `2026_01_01_000009_update_cms_form_submissions_add_lead_status_table`:
  `down()` dropped the column while its index existed. It now drops the
  index first.

Rejected alternatives: a role column on `tbtop_user_profiles` (a migration
and a role UI for one flag); a media-ability hook in tbtop/admin (cleaner,
but needs a release of the second package; deferred).

Tests: `tests/Admin/UsersPermissionsTest.php`,
`tests/Admin/MediaApiAbilitiesTest.php`, `tests/Form/SubmissionRetrierTest.php`
(three retry cases through the admin action). `UsersPageTest` cases that had
a non-owner edit or revoke another admin now use the owner or a target
without access.

**Read by eye:**
- `src/Admin/Pages/UsersPage.php`
- `src/Admin/Http/Middleware/AuthorizeMediaApi.php`
- `src/Form/SubmissionRetrier.php`
- `src/Form/SubmissionNotifier.php`

**Assumptions:**
- tbtop/admin keeps its media route names (`tbtop.{panel}.media.*`);
  `MediaApiAbilitiesTest` fails when they change.
- Retry re-sends to every enabled channel, including those that succeeded
  before; duplicates are accepted.
