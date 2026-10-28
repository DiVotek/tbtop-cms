# Collections — records that are not pages

A **collection** is a kind of record with no page of its own: reviews, team
members, vacancies, FAQ entries, offices. Its schema is code, like a block's
form; its records (**entries**) are rows in `tbtop_entries`, edited in the
admin and read on the site through a block field or `Cms::collection()`.

Collection or page type? A page type when the record has its own URL, SEO and
blocks (a service, an article). A collection when the record only appears
inside other pages — reviews shown on a service and on the home page, a team
grid, the offices in the footer. A collection entry has no URL, slug,
revisions, scheduling or trash; its history is the audit log.

## Declaring one

```php
namespace App\Cms\Collections;

use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Collection\Collection;

final class Reviews extends Collection
{
    public function key(): string { return 'review'; }

    public function label(): string { return 'Reviews'; }

    public function fields(S $s): array
    {
        return [
            $s->text('author')->required(),
            $s->textarea('text')->translatable(),
            $s->number('rating')->rules('nullable|integer|between:1,5'),
            $s->media('photo'),
            // A `pages` field ties an entry to pages: the "related" mode reads it.
            $s->pages('services')->of('service'),
        ];
    }
}
```

Register it in `config/tbtop-cms.php`:

```php
'collections' => [App\Cms\Collections\Reviews::class],
```

or from a provider with `CollectionRegistry::extend(fn () => [Reviews::class])`
(before the registry's first use, as `PageTypeRegistry::extend()`).

`Collection` (`src/Collection/Collection.php`):

| Method | Default | Meaning |
|---|---|---|
| `key(): string` | — | `[a-z][a-z0-9_-]*`, ≤64 chars; stored in `tbtop_entries.collection` and used in admin URLs |
| `label(): string` | — | Plural name: menu item, list title |
| `fields(S $s): list<Field>` | — | The entry form. Any field kind a block form takes. `status`, `lock_version`, `id` are reserved; a `collection` field is refused (an entry cannot pick entries) |
| `singularLabel()` | `label()` | The "Add …" button |
| `titleField()` | the first field | The entry's name in the admin list and pickers; kept in the `title` column (default locale) on every save |
| `relatedField()` | the first `pages` field, else null | What "related to this page" filters on |
| `icon()` / `navigationGroup()` / `navigationOrder()` | `list` / `content` / `50` | The menu item |
| `resolve(array $input, ResolveContext $ctx): array` | `FormResolver` by field kind | One entry as the site gets it, from values flattened to the locale. `$ctx->page` is null: an entry is resolved once per locale, not per page |

The registry throws on a bad key, a duplicate key, a reserved field name or a
nested `collection` field — at the first use, so a typo fails the boot of the
admin, not a page render.

## Admin

Each collection gets a menu item (`navigationGroup()`), linking to
`{admin.prefix}/collections/{key}` (`CollectionEntriesPage`): a table of
entries (name, status, last change) with search, drag-to-reorder, click to
edit and per-row delete, and an "Add" modal with the collection's form. The
entry editor is `{admin.prefix}/collections/{key}/{entry}/edit`
(`CollectionEntryEditPage`), with a draft/published status and the same
optimistic lock as shared blocks. Both pages answer to the `tbtop-cms.pages`
ability. A host config that published `admin.pages` before collections
existed must add `collection_entries` and `collection_entry_edit`, or the
menu items are left out.

Embed an entries table in any admin page — a service editor listing its
reviews, in place of a relation manager:

```php
use Tbtop\Cms\Admin\Collections\CollectionTable;

CollectionTable::make($s, 'review')->relatedTo($page)->build()
```

## Site

### In a block: the `collection` field

```php
$s->collection('reviews')->of('review')->label('Reviews'),
$s->collection('featured')->of('review')->single()->modes(['manual', 'latest']),
$s->collection('team')->of('team')->max(8),
```

The editor picks the mode per block:

| Mode | Picks |
|---|---|
| `all` | every published entry, editor's order |
| `latest` | the newest N (N set in the block, default 3) |
| `related` | the entries whose `relatedField()` picks the page being rendered; offered only when the collection has a `pages` field |
| `manual` | entries picked by hand, in the picked order |

`->modes([...])` limits the choice (the first is the default for an untouched
field), `->max(n)` caps every mode, `->single()` gives one entry or `null`
instead of a list. Stored value: `{mode, limit?, ids?}` (not translatable);
validated by the `collection_value` rule (mode allowed, limit within `max`,
picked ids exist in that collection — drafts may be picked and are skipped on
the site until published).

Props: `list<{id: int, ...fields}>` — each field resolved by its kind, as a
block without its own `resolve()` resolves (media → URL, `pages` →
`{id, name, url}` list, richtext → HTML, …); `{id, ...}|null` with
`single()`. `cms:blocks:types` types the prop from the collection's fields; a
collection with its own `resolve()` returning another shape needs the block to
declare its props in its own `resolve()` docblock.

The admin side of the field is a client component: import the package's
admin blocks module once (`resources/js/cms/admin/blocks.ts`, which exports
`collectionFieldClient`), as for the `link` field.

### In host code: `Cms::collection()`

```php
use Tbtop\Cms\Facades\Cms;

Cms::collection('review')->all();                       // list<{id, ...}>
Cms::collection('review')->relatedTo($page)->take(6)->all();
Cms::collection('review')->latest(3)->all();
Cms::collection('review')->only([12, 7])->all();         // in this order
Cms::collection('review')->where('rating', 5)->first();
Cms::collection('review', 'uk');                          // another locale
```

`EntryList` (`src/Collection/EntryList.php`) is immutable and iterable; every
filter runs in memory.

## Cache and limits

The whole published collection is resolved once per locale and cached under
the content version (`CmsCache::remember()`, key `collection:{key}:{locale}`).
Every model write of an entry (`EntrySaved`, `EntryDeleted`, after the commit),
a query update such as the drag reorder (`EntriesUpdated`), and every page,
settings or language save bump the version. A row written behind the model
(raw SQL, `DB::table('tbtop_entries')`) is not seen until the next bump.

Collections are meant for hundreds of entries, not tens of thousands: there is
no SQL filtering on entry fields. Domain data at that scale (products,
bookings) stays a host model.

## Built-in collections

Always registered (override one by binding a subclass in the container):

| Key | Class | Fields | Read by |
|---|---|---|---|
| `offices` | `Collection/BuiltIn/Offices.php` | `name`, `street`, `locality`, `region` (translatable), `postal_code`, `country`, `phones[{number}]`, `emails[{address}]`, `opening_hours[{days, opens, closes}]`, `latitude`, `longitude` | `SiteContacts::phones()/emails()/offices()`, the identity JSON-LD (seo.md) |
| `socials` | `Collection/BuiltIn/Socials.php` | `name`, `label` (≤8), `href` | `SiteContacts::socials()`, identity `sameAs` |

The first published office is the main one: its phones, emails, address,
hours and coordinates are the site's. Both sit under Settings in the menu;
Site settings → Contacts links to them. They replaced the
`site.contacts.*` settings and the SEO identity's address, hours and
coordinates (migration `2026_10_28_000001_move_contacts_to_collections`). The
shared `cms.settings` prop still carries `site.contacts.phones/emails/socials`
in their old row shapes, built from the collections.

## Seeding

```php
use Tbtop\Cms\Collection\Models\Entry;

Entry::query()->updateOrCreate(
    ['collection' => 'review', 'title' => 'Anna'],
    ['data' => ['author' => 'Anna', 'text' => ['en' => 'Great', 'uk' => 'Чудово']], 'status' => Entry::PUBLISHED, 'sort_order' => 1],
);
```

Model writes report themselves (`EntrySaved`), so the site's cache follows a
seeder; `title` is filled from the title field on save.
