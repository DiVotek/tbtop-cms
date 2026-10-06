# fix(shared-blocks): design ability on "Make shared", shared-block locking, one source for site contacts

touches: page editor (block tabs), shared block edit page, shared block model, SEO settings Identity tab, identity JSON-LD, settings, migrations

> ⚠️ **Contract** With `tbtop-cms.roles` on, "Make shared" (`pageBlockShare_{id}`) is shown and accepted only when `tbtop-cms.design` allows; a direct post without it is a 403.
> ⚠️ **Contract** Settings keys `seo.identity.telephone`, `seo.identity.email` and `seo.identity.same_as` are removed; the identity reads `site.contacts.*` only.
> ⚠️ **Migration** `database/migrations/2026_10_07_000000_add_lock_version_to_shared_blocks_table.php` — adds `tbtop_shared_blocks.lock_version` (unsigned int, default 0); guarded no-op if the column exists.
> ⚠️ **Migration** `database/migrations/2026_10_07_000001_move_identity_contacts_to_site_contacts.php` — copies each legacy identity value into the empty contacts list, then deletes the identity keys; plain `Settings` reads/writes, rerun-safe, no `down()` restore.

Three gaps around shared blocks and site contacts:

- "Make shared" creates a shared block, a design-section object, but ran
  under the pages ability. It now also needs `tbtop-cms.design` when abilities
  are on, like the shared blocks pages themselves.
- Two editors on one shared block overwrote each other. The edit form now
  carries `lock_version`; a save whose version is behind is rejected with an
  error notify and the form resyncs, as on pages. The version is bumped on
  every update at the model. No drafts or revisions for shared blocks — out of
  scope; the conflict message therefore does not point to Revisions.
- Telephone, email and profile links lived in both SEO identity and Site
  contacts, with the identity value winning silently. Site contacts are now
  the only source; the Identity tab says where they come from.

Rejected alternative: keeping both sources with a "value comes from SEO"
hint — it treats the symptom; two editable copies of one fact still drift.

Tests: `tests/SharedBlock/SharedBlockAdminTest.php` ("offers and accepts
"Make shared" only to an editor with the design ability", "rejects a save
from a form loaded before another editor saved, keeping the first save"),
`tests/Frontend/JsonLdLocalBusinessTest.php` ("gives an Organization the
telephone of Site settings contacts…", "no longer reads the legacy SEO
identity…"), `tests/Settings/IdentityContactsMigrationTest.php`,
`tests/Admin/SettingsPagesTest.php` ("leaves telephone, email and profile links
to Site settings contacts…"); `ServerHeadTest`/`PageSeoTest` sameAs filters
now drive `site.contacts.socials`.

**Read by eye:**
- `src/Admin/Pages/Support/PageEditBlockTabs.php`
- `src/SharedBlock/Models/SharedBlock.php`
- `src/Admin/Pages/Support/SharedBlockForm.php`
- `database/migrations/2026_10_07_000001_move_identity_contacts_to_site_contacts.php`

**Assumptions:**
- A moved profile link is labelled from its host (`facebook.com` → `facebook`),
  since a Contacts social row needs a label; editors rename it there.
- Where both sources were set, the contacts value wins and the identity value
  is dropped — the editor sees the contacts value on the Contacts tab.
- The lock is check-then-save like pages', guarding against a form gone stale
  over minutes, not two requests racing within milliseconds.
