# feat(collections): code-defined collections, built-in Phones, Emails and Socials

touches: new table, admin nav, block field kinds, site contacts, settings keys, migrations

> ⚠️ **Migration** `2026_10_28_000000_create_entries_table.php` creates `tbtop_entries` (`collection`, json `data`, `title`, `status`, `sort_order`, `lock_version`); guarded no-op if the table exists.
> ⚠️ **Data migration** `2026_10_28_000001_move_contacts_to_collections.php` moves each row of `site.contacts.phones/emails/socials` into a published entry of the matching collection, then deletes that key; a collection that already has entries keeps its key. No `down()`.
> ⚠️ **Constructor change** `SiteContacts` takes `CollectionEntries` instead of `Settings`; resolve it from the container.
> ⚠️ **Published config** a host `admin.pages` needs `collection_entries` and `collection_entry_edit`.

A collection is a kind of record that is not a page (reviews, team,
vacancies): a `Collection` class (key, label, fields) in
`config('tbtop-cms.collections')`, entries in `tbtop_entries` with a
draft/published status, the editor's order and an optimistic lock. One
Collections page (one menu item) shows each collection as a tab with search,
drag reorder, row delete and an Add modal, plus an edit page;
`CollectionTable::make($s, $key)->relatedTo($page)` embeds the list in any
admin page.

On the site, the block field `$s->collection('x')->of($key)` lets the editor
pick all entries, the latest N, those related to the page (through the
collection's `pages` field) or a hand-picked list; `->single()`, `->max()`,
`->modes()`. `Cms::collection($key)` returns the same entries for host code.
The whole published collection is resolved once per locale and cached under
the content version; filters run in memory.

Built in: Phones, Emails and Social links. They replace Site settings →
Contacts; `SiteContacts` keeps its methods and the `cms.settings` prop keeps
the `site.contacts.*` keys. The identity's address, hours and coordinates
stay in SEO settings.

**Read by eye:**
- `database/migrations/2026_10_28_000001_move_contacts_to_collections.php`
- `src/Collection/CollectionEntries.php`
- `src/Collection/EntryList.php`
- `src/Admin/Fields/CollectionField.php`
- `resources/js/cms/admin/collection/collectionField.tsx`

**Assumptions:**
- Collections stay small (hundreds of entries): no SQL filters on entry fields, no refs index.
- No revisions, scheduling or trash for entries; the audit log is the history.
- The Collections page answers to the `tbtop-cms.pages` ability.
