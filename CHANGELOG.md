# Changelog

## Unreleased

### Breaking — the built-in role system was removed

The package no longer ships roles. `CmsRole` (superadministrator /
administrator / editor), the `cms_user_profiles.role` column, and the eight
`Gate::define()` calls that backed them are gone. Admin access itself is
unchanged: a `cms_user_profiles` row, or a user model implementing `CmsAdmin`.

**Roles are now the host's responsibility.** Each admin page declares a
`tbtop-cms.*` ability, and the new `tbtop-cms.roles` config flag decides who
answers it. With the flag off (the default) no ability is declared and the gate
is never consulted; with it on, your app must define all eight gates, since an
ability the package does not define denies by default.

#### Upgrading

Existing installs have roles in `cms_user_profiles.role`. The included
migration drops that column. **Under the default `roles => false`, every user
who had admin access — including editors — gains access to every section**,
among them form submissions (lead PII), template editing, and global settings.

- To keep a separation of duties, set `tbtop-cms.roles => true` and define the
  eight `tbtop-cms.*` gates in your app **before** upgrading. See the README.
- To accept the flat model, no action is needed beyond running migrations.

`UsersPage` remains, reduced to granting and revoking access (later restored
to also create and edit users — see Added below); it no longer assigns roles.
`cms:admin:create`, `cms:admin:grant`, and `cms:admin:revoke` are unchanged.

### Added

- `Tbtop\Cms\Admin\Events\CmsAccessGranted` and `CmsAccessRevoked` domain
  events, dispatched when a user's cms admin access is granted or revoked
  (from the Users page or the `cms:admin:grant`/`revoke` commands). A
  roles/permissions extension package can subscribe to seed or clean up its
  own per-user assignments.
- Users can now be created and edited directly from the Users page (a UI
  mirror of `cms:admin:create`'s assumptions), not just granted/revoked
  access.
- A protected owner: `tbtop_user_profiles.is_owner` marks the first admin of
  a fresh install (or, on upgrade, the earliest existing admin — backfilled
  by migration). The owner cannot be revoked or have their name/email/
  password edited by anyone but themselves. This is a protection only — the
  owner gets no extra section rights; that axis is still entirely the host's
  business via `tbtop-cms.roles`.

### Changed

- `cms:admin:revoke` now refuses to revoke the owner, printing an explicit
  error and exiting with `FAILURE` instead of a false "revoked" success
  message.

### Fixed

- Self-revoke on the Users page is no longer bypassable on hosts whose user
  model has a non-integer key: identifiers are compared as strings, where an
  `(int)` cast previously collapsed every uuid/ulid to `0`.
