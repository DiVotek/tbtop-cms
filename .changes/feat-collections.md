# feat(collections): code-defined collections, built-in Offices and Socials

touches: new table, admin nav, block field kinds, site contacts, identity JSON-LD, settings keys, migrations

> ⚠️ **Migration** `2026_10_28_000000_create_entries_table.php` creates `tbtop_entries` (`collection`, json `data`, `title`, `status`, `sort_order`, `lock_version`); guarded no-op if the table exists.
> ⚠️ **Data migration** `2026_10_28_000001_move_contacts_to_collections.php` moves `site.contacts.*` and `seo.identity.address/opening_hours/geo` into one published office and the social entries, then deletes those settings keys; a collection that already has entries keeps its keys. No `down()`.
> ⚠️ **Constructor change** `SiteContacts` takes `CollectionEntries` instead of `Settings`; resolve it from the container.
> ⚠️ **Published config** a host `admin.pages` needs `collection_entries` and `collection_entry_edit`.

A collection is a kind of record that is not a page (reviews, team,
vacancies): a `Collection` class (key, label, fields) in
`config('tbtop-cms.collections')`, entries in `tbtop_entries` with a
draft/published status, the editor's order and an optimistic lock. Each
collection gets a menu item and a list page with search, drag reorder and
an Add modal, plus an edit page; `CollectionTable::make($s, $key)->relatedTo($page)`
embeds the list in any admin page.

On the site, the block field `$s->collection('x')->of($key)` lets the editor
pick all entries, the latest N, those related to the page (through the
collection's `pages` field) or a hand-picked list; `->single()`, `->max()`,
`->modes()`. `Cms::collection($key)` returns the same entries for host code.
The whole published collection is resolved once per locale and cached under
the content version; filters run in memory.

Built in: Offices (address, phones, emails, hours, coordinates; the first
published one is the main office) and Social links. They replace Site
settings → Contacts and the identity's address fields; `SiteContacts` keeps
its methods and the `cms.settings` prop keeps the `site.contacts.*` keys. Other
offices become `department` entries in the identity JSON-LD.

**Read by eye:**
- `database/migrations/2026_10_28_000001_move_contacts_to_collections.php`
- `src/Collection/CollectionEntries.php`
- `src/Collection/EntryList.php`
- `src/Admin/Fields/CollectionField.php`
- `src/Frontend/Seo/SiteIdentity.php`
- `resources/js/cms/admin/collection/collectionField.tsx`

**Assumptions:**
- Collections stay small (hundreds of entries): no SQL filters on entry fields, no refs index.
- No revisions, scheduling or trash for entries; the audit log is the history.
- Collection pages answer to the `tbtop-cms.pages` ability; a menu item has no gate of its own.
