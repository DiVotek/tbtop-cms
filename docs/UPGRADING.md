# Upgrading

## Table names now use the `tbtop_` prefix

All package tables have been renamed to a single `tbtop_` prefix, for example
`pages` → `tbtop_pages` and `cms_user_profiles` → `tbtop_user_profiles`. See
`CmsServiceProvider::configurePackage()` for the full migration list and each
`create_*_table` migration for its old/new name.

Existing installs: just run

```bash
php artisan migrate
```

The new `2026_01_01_000018_update_tables_add_tbtop_prefix_table` migration
renames each existing table to its `tbtop_`-prefixed name in place — data is
preserved, no manual steps required. A fresh install has nothing to rename:
the `create_*_table` migrations already create the tables under their new
names directly, so this migration is a no-op there.

Note: `Schema::rename()` does not rename a table's auto-generated index and
constraint names (e.g. an index created as `pages_type_index` stays named
that way after the table becomes `tbtop_pages`). This is left as-is on
upgraded databases — nothing in the package references index names by
string, so it's cosmetic only.

## `BlockRegistry` and `PageTypeRegistry` are now extendable from packages

Both registries gain a static `extend(callable $extension): void`. A package
that ships its own blocks or page types calls it with a callable returning
the classes to register, typically from its service provider's `register()`:

```php
BlockRegistry::extend(fn (): array => [ProductGallery::class, ProductBuyBox::class]);
PageTypeRegistry::extend(fn (): array => [ProductType::class]);
```

The callable is invoked once, lazily, the first time the registry is used
(`get()`/`has()`/`all()`/`kinds()`/`keys()`) — never at `extend()` time — so
provider boot order doesn't matter. Call `extend()` before that first use;
once a registry singleton has booted, a later `extend()` call is stored but
never read. Tests get `BlockRegistry::flushExtensions()` /
`PageTypeRegistry::flushExtensions()` for isolation between cases.

## `BlockContentField` moved to `Admin\Fields\BlockContent`

`Tbtop\Cms\Admin\Pages\Support\BlockContentField` is now
`Tbtop\Cms\Admin\Fields\BlockContent`, a public API for any host that needs
the block-content editor field outside cms's own page/layout forms.
`make()`, `toEnvelope()` and `toRows()` keep their existing behavior;
`make()` gains an optional `$name` parameter (default `'content'`) to mount
the repeater under a different form field. There is no compatibility shim —
update any reference to the old class name.

## Page subtypes and type fields

`PageTypeInterface` gains `base(): string`, `fields(S $s): list<Field>`,
`resolveFields(array $input, ResolveContext $ctx): array`,
`backReferences(): array` and `childSubtype(): ?string`; `BasePageType`
returns the type's own key, `[]`, a `FormResolver` pass over `fields()`,
`[]` and `null`, so a type extending it is unaffected. A host implementing
the interface directly must add the five methods.

`tbtop_pages` gains `subtype` (nullable string) and `fields` (JSON). A type
whose `base()` is another type's key is a **subtype**: pages of it store
the base in `type` and the subtype key in `subtype`; tree rules come from
the base. Its `fields()` show on the General tab and ship as `page.fields`
(and `ResolveContext::field()`); `page.subtype` ships next to `page.type`.
`placements()` may name a subtype key.

The migration `2026_10_06_000000_add_subtype_and_fields_to_pages_table`
adds the columns and, for every type registered when it runs with
`base() !== key()`, moves pages from `type = <subtype>` to
`type = <base>, subtype = <subtype>` — so **register your subtype classes
before running it**. It also strips the `owner` key from content rows. It
does not move the data of a former "general" type block into `fields`: the
package no longer knows which kinds were general where. Those rows stay in
`content` as ordinary blocks; copy their `data` into `fields` and drop the
rows in your own migration.

**A block that is placeable nowhere needs no component.** `cms:blocks:types`
skips a block whose `placements()` is `[]` and requires a component file for
every other one. See pages.md, "Subtypes and type fields".

## Pages can be backed by a source (source-backed page types)

`tbtop_pages` gains one nullable column, `source_id` (indexed together with
the existing `type` column — the page's own `type` already identifies which
kind of source it is, so there is no separate `source_type` column), plus
three new `PageTypeInterface` methods:

```php
public function isSourceBacked(): bool;
public function source(Page $page): ?object;
public function template(Page $page): ?array;
```

`Tbtop\Cms\Page\Types\BasePageType` implements `isSourceBacked()` as
`false` and `source()`/`template()` as `null`, so `PageType`, `CategoryType`,
`ArticleType` and any host type that already extends it keep working
unchanged — the interface growth is backward compatible. A host type that
implements `PageTypeInterface` directly (without extending `BasePageType`)
must add the three methods itself.

A type opts into being source-backed by returning `true` from
`isSourceBacked()` and loading its domain object from `(type, source_id)`
in `source()` — the type already knows what kind of source it loads.
`PageTypeRegistry` gains `isSourceBacked(string $key): bool` and
`sourceBackedKeys(): list<string>` to query this without a `Page` instance.

On the frontend, `page.content === null` now means "render the type's
template": `PageController` calls `$type->template($page)` when content is
null, and passes the resolved `source()` through to every block via a new
`ResolveContext::$source` property (`BlockContentResolver::resolve()` gains
a matching `?object $source` parameter, appended last so existing positional
callers are unaffected). A source-backed page whose `source()` resolves to
`null` (the underlying record was deleted or is unpublished) 404s exactly
like an unpublished page.

In the page editor, a source-backed page shows a read-only "Source" card
(type, source_id, and the source's string form when it is `Stringable`)
instead of the type select, and hides block editing entirely — `content` is
left untouched on save, so it stays `null` and keeps rendering the template.
The create form and the edit sidebar's type select both exclude
source-backed types: creating or retyping one by hand is rejected server-side
too, a host that syncs pages from another source owns that.

## Notification type and channel registry

A host that has never touched notification settings needs no changes:
`form.submission` (the contact-form fan-out) keeps its original bell-only
behavior. A host that *had* enabled mail or Telegram for form submissions
needs one step — see "Merchant-editable templates and per-type channel
lists" further below, which also covers this in full. Run

```bash
php artisan migrate
```

for the new `tbtop_notification_log` table (one row per channel delivery
attempt: type, channel, recipient, locale, status, error, sent_at).

This is for a host or package that wants to send its own notifications
through the same infrastructure instead of calling Laravel's notification
system directly.

**Register a channel** — a delivery mechanism (SMS, a webhook, a push
service) implementing `Tbtop\Cms\Notifications\Contracts\Channel`:

```php
use Tbtop\Cms\Notifications\Notifications;

Notifications::channel(MyChannel::class);
```

**Register a type** — what gets sent, to whom, and with which templates:

```php
use Tbtop\Cms\Notifications\{Audience, Format, Notifications, TypeDefinition};

Notifications::type(
    TypeDefinition::make('order.placed')
        ->label('Order placed')
        ->group('orders')
        ->payload(OrderPlacedPayload::class)          // readonly DTO; public props become template variables
        ->templates([
            Format::Html->value => 'orders::notifications.placed.html',
            Format::Short->value => 'orders::notifications.placed.short',
        ])
        ->audiences([Audience::Customer, Audience::Staff])
        ->defaultChannels(['mail']),                  // merchant can add more from NotificationSettingsPage later
);
```

**Send** — from a domain event listener, once you have the recipients:

```php
use Tbtop\Cms\Notifications\{Audience, Notifications, Recipient};

$deliveries = Notifications::send('order.placed', new OrderPlacedPayload($order), [
    new Recipient(Audience::Customer, $order->locale, email: $order->email),
]);

foreach ($deliveries as $delivery) {
    if ($delivery->failed()) {
        // $delivery->channel, $delivery->recipient, $delivery->error
    }
}
```

`Notifications::send()` is synchronous: it renders and delivers to every
enabled, supporting channel for every recipient, writes a
`tbtop_notification_log` row and dispatches `NotificationSent` or
`NotificationFailed` per delivery, and only returns once every one of those
has happened. One channel throwing does not stop the others — it's logged
as failed and the rest still run. This is deliberate: a caller that needs
the outcome (did it send? what failed?) can't get that from a call that
returns before delivery happens. If you don't need the outcome and want the
call off the request/response path, run it from a queued job or listener
yourself — `send()` will not queue it for you.

It returns one `Delivery` per attempted (recipient, channel) pair, in
dispatch order, each carrying the channel key, the recipient, a `Sent` or
`Failed` status and the error. Use that return value to decide your own
state — do not reconstruct it by querying `tbtop_notification_log`: the log
is shared by every concurrent request and has no column tying a row to the
entity that triggered the send, so a window query there will pick up another
caller's failures.

## Merchant-editable templates and per-type channel lists

Run

```bash
php artisan migrate
```

for the new `tbtop_notification_templates` table (one row per merchant
override: type, format, locale, subject, body — unique on
`(type, format, locale)`).

**`TemplateStore` is now database-backed.** `NullTemplateStore` (the
package's previous default binding, which always returned `null`) is
removed; `Tbtop\Cms\Notifications\Contracts\TemplateStore` is now bound to
`Tbtop\Cms\Notifications\Support\DatabaseTemplateStore`. A host that had
rebound `TemplateStore` to its own implementation keeps working unchanged —
the contract itself did not change. A host that referenced
`Tbtop\Cms\Notifications\Support\NullTemplateStore` by class name (e.g. in a
container binding or a test) must drop that reference; the class no longer
exists.

**`form.submission`'s legacy enabled flags are no longer read.**
`notifications.mail.enabled` and `notifications.telegram.enabled` — the flat
toggles `NotificationSettingsPage` used to write — are no longer consulted
anywhere in `src/`. `form.submission` now resolves mail/telegram/bell
participation the same way every other registered type does: from
`Notifications::enabledChannels('form.submission')`, i.e.
`notifications.form.submission.channels` in `Settings`, falling back to the
type's registered default (`['bell']`) when unset. **A host that never
touched notification settings keeps bell-only delivery** — the same
behavior a fresh install always had. A host that relied on
`notifications.mail.enabled` / `notifications.telegram.enabled` being `true`
to get mail/Telegram delivery on form submissions must carry that opt-in
over explicitly, e.g. from a migration or `AppServiceProvider::boot()`:

```php
app(\Tbtop\Cms\Settings\Settings::class)->set(
    'notifications.form.submission.channels',
    ['bell', 'mail'], // add 'telegram' too if it was enabled
);
```

The old `notifications.mail.enabled` / `notifications.telegram.enabled` rows
may stay in `tbtop_settings` — they're simply unused now, no migration
removes them.

**The settings page now generates per-type UI from the registry.**
`NotificationSettingsPage` shows a "Channels" tab (mail transport, Telegram
transport — unchanged) plus one tab per registered type's `group()`, each
type getting a channel checkbox list (offering only channels whose format it
has a template for) and, for owner admins only (`is_owner` on
`tbtop_user_profiles`), a Blade template editor per format/locale with a
reset-to-built-in action. A non-owner admin sees the channel checkboxes but
no template fields.

## Site contacts are the only source of the identity's telephone, email and profile links

The SEO settings → *Identity* tab no longer has telephone, email or profile
link (sameAs) fields, and the `seo.identity.telephone`,
`seo.identity.email` and `seo.identity.same_as` settings keys are gone. The
identity JSON-LD takes the first phone, the first email and every social
link from Site settings → *Contacts* (`site.contacts.phones`,
`site.contacts.emails`, `site.contacts.socials`, read via
`Tbtop\Cms\Settings\SiteContacts`).

Run `php artisan migrate`. The `2026_10_07_000001_move_identity_contacts_to_site_contacts`
migration copies each legacy identity value into the matching contacts list
when that list has no entry yet, then deletes the identity key. Where both
were set, the contacts value is kept and the identity value is dropped. A
moved profile link gets a label and name derived from its host
(`https://www.facebook.com/acme` → label `facebook`, name `facebook.com`);
rename them on the Contacts tab. Non-URL profile entries are not moved. The
migration has no `down()` restore.

A host that writes these keys from code (a seeder, a migration) writes the
`site.contacts.*` lists instead; a value written to the old keys is no longer
read.

## "Make shared" needs the design ability

With `tbtop-cms.roles` on, the page editor's "Make shared" action (`pageBlockShare_{id}`) is shown and accepted (otherwise 403) only when `tbtop-cms.design` allows; with roles off nothing changes.

## Blocks without their own `resolve()` now get form-resolved props (#238)

`Block::resolve()` used to return its input unchanged. The default now runs
`Block\Support\FormResolver` over the block's `form()`, so a host block that
does not override `resolve()` hands its React component different props:

- a `media` field ships the media **URL**, not the stored id (`->multiple()`:
  a list of URLs of media that still exist);
- a `link` field ships `{href, type, label}` with `href` the resolved path or
  URL, not the stored `{type, value, label}`;
- a translatable field is a plain string in the current locale (it already
  was, flattened before `resolve()`), but a blank one is now `null`, not `''`;
- every text-like value is trimmed and blank becomes `null`; numbers are
  floats, booleans `bool`, repeater rows with no filled field are dropped;
- **a key in the stored data with no field in `form()` is dropped** — data a
  block kept outside its form (seeded, imported) no longer reaches the client.

`cms:blocks:types` types such a block from the same rules instead of the
permissive `z.record()` fallback. To keep the old pass-through for a block,
override `resolve()`:

```php
/** @return array<string, mixed> */
public function resolve(array $input, ResolveContext $ctx): array
{
    return $input;
}
```

Blocks with their own `resolve()` are unaffected.

## A block that cannot render is skipped; the editor lists it under Problem blocks

A content row whose kind is no longer registered, or whose `shared` pointer
leads to a missing shared block or one of another kind, is skipped by the
site: no element in `render`, no entry in `cms.layout`, nothing in the log —
in preview too. Its data stays in `content` untouched, and the page and
layout editors list it under **Problem blocks (N)** with the reason, the
saved data and a "Delete row" action; such a row no longer gets a tab. A
`resolve()` that throws drops its block as well and is still logged as
`block.resolve_failed`. `blocks.strict` throws exactly as before.

`render` stays `{root, elements}` and a `cms.layout` slot item stays
`{id, kind, props}`. Hosts render pages and layout slots through
`@cms/CmsRenderer` (`showErrors={isPreview || cms.debug}`): a block with no
component, props failing its catalog schema or a component that throws
renders nothing, and `showErrors` only adds a developer `console.error`
line. `cms:doctor` gains `unregistered_block_rows` next to
`broken_shared_references`.

## `page.fields` is always a JSON object

It used to serialise as `[]` when empty. It is now always an object — `{}`
when empty. Code that checked `Array.isArray(...)` or `.length` on it reads
it as a map instead (`Object.keys(...).length`).

## Page texts fall back to the default language when blank

`page.name`, `page.title`, `page.heading` and `page.summary` (page props and
error-page props), breadcrumb labels, the meta title/description and the
JSON-LD names now follow the rule `page.body` already did: this locale, then
the site's default language, then `null`. A translation saved as `''` or
whitespace counts as missing. Before, a blank translation shipped `''`, and
a missing one fell back to `app.fallback_locale` rather than the default
language. The four page props are typed `string | null`.

## Upgrading from `main` to this release (checklist)

The sections above cover each change in depth; this is what a host does by
hand, in order.

1. **Run `php artisan migrate`.** New migrations, in order:
   `2026_10_03_000002_create_shared_blocks_table`,
   `2026_10_06_000000_add_subtype_and_fields_to_pages_table`,
   `2026_10_07_000000_add_lock_version_to_shared_blocks_table`,
   `2026_10_07_000001_move_identity_contacts_to_site_contacts`,
   `2026_10_07_000002_create_page_refs_table`. Register host page-type
   classes (`PageTypeRegistry::extend()` or config) before migrating: the
   subtype migration reads the registry. Rolling back is lossy:
   - `move_identity_contacts_to_site_contacts` has an empty `down()`; the
     old `seo.identity.*` keys are not restored.
   - `add_subtype_and_fields` `down()` drops the `subtype` and `fields`
     columns, so every stored `fields` value is lost (and pages already
     moved to `type = <base>` do not move back).
   - Dropping `tbtop_shared_blocks` (`create_shared_blocks` `down()`)
     empties every block that points at one: those rows hold only
     `shared: <id>` and become Problem blocks.
2. **Run `php artisan cms:page-refs:rebuild` once** if any page type has a
   `pages`-kind field, or its reverse lists stay empty until each source
   page is saved again.
3. **Page types that implement `PageTypeInterface` directly** add the five
   methods listed under "Page subtypes and type fields". Types extending
   `BasePageType` need nothing.
4. **A published `config/tbtop-cms.php` needs two new keys.** Add
   `admin.pages.shared_blocks` (`Tbtop\Cms\Admin\Pages\SharedBlocksPage::class`)
   and `admin.pages.shared_block_edit`
   (`Tbtop\Cms\Admin\Pages\SharedBlockEditPage::class`) next to
   `layout_edit`. The service provider merges config with
   `mergeConfigFrom`, which is shallow: a published config replaces the
   whole `admin` array and never receives new nested keys. Without the two
   keys the shared-block routes do not exist and "Make shared" in the page
   editor fails with a 500. The new `blocks.discover`, `blocks.registry_path`
   and `blocks.components_dir` keys are optional: the code falls back to
   their defaults when they are missing.
5. **Regenerate the block files.** `php artisan cms:blocks:types` now also
   writes `registry.generated.ts` (next to the types file; catalog plus a
   component map) when the components folder (`blocks.components_dir`,
   default `./blocks`, relative to the registry file) exists. It then fails
   if a registered block that is placeable somewhere has no
   `<StudlyKind>.tsx` exporting `<StudlyKind>` there, or if two kinds map to
   one component name. `--check` verifies both files. A host that keeps the
   folder but its own catalog and registry sets `blocks.registry_path` to
   `false`; a host without the folder gets only the types file, as before.
   Block classes can be found by scanning folders with
   `blocks.discover` (`namespace => directory`, subfolders map to
   sub-namespaces) instead of listing each one in `blocks.types`.
6. **A leaf's type follows its category.** A page given a category becomes
   an article; an article whose category is removed becomes a page. Under
   other containers that allow `page` children (a host `section`, say) a
   page stays a page. The pages list tabs "Pages" and "Articles" now filter
   by `type` (`page` / `article`), not by "no parent" / "has a parent". A
   type change that would orphan the page's stored subtype is rejected on
   `type`; it used to drop the subtype silently.
7. **`BlockContentResolver` has new constructor dependencies**
   (`SharedBlocks`, `PageTypeRegistry`, `BackReferences`). This only matters
   to a host that builds it with `new`; container resolution is unaffected.
8. **Content rows may carry `shared: <id>` with an empty `data`.** A seeder,
   export or any code that reads a page's or layout's `content` directly
   sees no data for such a row; resolve it with
   `Tbtop\Cms\SharedBlock\SharedBlocks::dataOf($row, $kind)`.

The remaining behaviour changes (form-resolved block props, Problem blocks,
`page.fields` as an object, page-text fallback, site contacts) have their own
sections above.
