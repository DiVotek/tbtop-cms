# feat(pages): page subtypes and type fields

touches: page type contract, page model and migration, page editor (General tab, sidebar, create modal, block picker), pages list, block resolution, page render, revisions, blocks:types registry emitter, translations

> ⚠️ **Contract** `PageTypeInterface` gains `base(): string`, `fields(S $s): list<Field>` and `resolveFields(array $input, ResolveContext $ctx): array`; `BasePageType` returns the own key, `[]` and a `FormResolver` pass over `fields()`. A host implementing the interface directly breaks at load.
> ⚠️ **Contract** `tbtop_pages` gains `subtype` (nullable, indexed) and `fields` (JSON). `type` always holds a top-level type; a subtype key lives in `subtype`. The migration moves existing `type = <subtype>` pages for every subtype registered when it runs.
> ⚠️ **Contract** page props gain `page.fields` and `page.subtype`. `ResolveContext` gains a public `fields` array and `field(string)`. `BlockContentResolver::resolveWithFields()` returns `{blocks, fields}`; `resolve()` is unchanged.
> ⚠️ **Contract** `Block::placements()` may name a subtype key; `BlockRegistry::forPlacements(array)` is new. `cms:blocks:types` skips a block whose `placements()` is `[]` and requires a component of every other one.
> ⚠️ **Contract** page forms gain a `subtype` field; `PageFormOptions::types()` lists top-level types, `subtypes(string $base)` the subtypes of one type (`[]` when there are none).

A subtype is a key plus fields. A type whose `base()` names another type is
a subtype of it: pages store the base in `type` and the subtype key in
`subtype`, so every tree rule, guard and lookup keyed on `type` reads the
base and needs no look-through. The sidebar "Subtype" select lists only the
subtypes of the page's type and is hidden when there are none; the create
modal offers the subtypes of `page`; the pages list gets a Subtype column and
filter once any subtype is registered.

`fields()` declares a type's own values. The General tab shows the base
type's fields and then the subtype's (form names prefixed `field_`), saved
into the `fields` column by merge — a subtype switch never deletes anything.
On the site they ship as `page.fields`, locale-flattened, after an optional
`resolveFields()` pass, and every block reads them through `$ctx->field()`.
A block placed on a subtype key is offered in "+ Add block" on that subtype
only. `fields` is an editorial field for revisions; `subtype` is not.

Rejected alternative: keeping the subtype key in `type` with the fields as a
component-less "general" block in the content envelope (the shape #242
shipped). Every path keyed on `type` — tree rules, guards, starter blocks,
placements — had to look through to the base, and the fields needed a block
with no component, `owner` markers, dormant rows on a type switch and a
purge action.

Tests: `tests/Page/PageFieldsTest.php` drives the General tab render and
save, `page.fields` (base + subtype, translatable flattened, a
`resolveFields()` override, read from a block via `$ctx->field()`), a subtype
change keeping `fields`, placement by subtype key in "+ Add block" and the
revision snapshot/restore. `tests/Page/PageSubtypeTest.php` covers the
Type/Subtype options with a top-level host type, the save writing `subtype`
with `type` unchanged, create via subtype, a stale or foreign subtype, tree
rules from the base and the pages-list filter.
`tests/Page/PageSubtypesMigrationTest.php` runs the migration class over
pre-existing rows (type move, `owner` stripped on a trashed page too, rerun
is a no-op). `tests/BlockTypes/BlocksTypesCommandRegistryTest.php` covers the
registry rule. Fixtures `EventPageType`, `ConcertPageType` and
`ConcertTicketBlock` are new; `ContactsPageType` is now a plain subtype.

**Read by eye:**
- `database/migrations/2026_10_06_000000_add_subtype_and_fields_to_pages_table.php`
- `src/Page/Contracts/PageTypeInterface.php`
- `src/Page/Types/BasePageType.php`
- `src/Page/PageTypeRegistry.php`
- `src/Page/Models/Page.php`
- `src/Block/BlockContentResolver.php`
- `src/Block/BlockRegistry.php`
- `src/Admin/Pages/Support/PageForm.php`
- `src/Admin/Pages/Support/PageFormOptions.php`
- `src/Admin/Pages/Support/PageEditTypeFields.php`
- `src/Admin/Pages/Support/PageEditBlockTabs.php`
- `src/Admin/Pages/PagesPage.php`
- `src/Page/Support/PageRevisionWriter.php`
- `src/Console/BlocksTypesCommand.php`

**Assumptions:**
- A host registers its subtype classes before running the migration; a subtype registered later leaves its old pages with the subtype key in `type` until the host moves them.
- The host moves the data of former general type blocks into `fields` itself; the package cannot tell which content rows were general.
- A subtype's own tree-rule and `schemaTypes()` overrides are not read; everything keyed on the page type reads the base. SEO title/description templates are the one exception: a page reads its subtype's template before its type's.
- A stored subtype outlives its registration: the page keeps it on save while its type is unchanged.
