# feat(pages): page types declare their own blocks and a base

touches: page type contract, page observer, block content envelope, page editor (General tab, block tabs, block picker), block resolution, page render, blocks:types registry emitter, translations

> ⚠️ **Contract** `PageTypeInterface::blocks(): list<TypeBlock>` and `base(): string` — new methods; `BasePageType` returns `[]` and the own key. A host implementing the interface directly breaks at load.
> ⚠️ **Contract** page forms gain a `subtype` field; `PageFormOptions::types()` lists top-level types only, `subtypes()` the page subtypes. The stored `type` column is unchanged.
> ⚠️ **Contract** content envelope rows gain an optional `owner` key (the type key). `BlockContent::toEnvelope()` keeps it on the row, not in `data`; `toRows()` carries it back.
> ⚠️ **Contract** `BlockContentResolver::resolveWithFields()` returns `{blocks, fields}`; `resolve()` is unchanged in signature and now skips rows owned by a type other than the page's. `ResolveContext` gains a public `fields` array and `field(string)`. Page props gain `page.fields`.

A page type lists the blocks every page of it carries. A content block is a
normal block tab without "Remove" and "Make shared"; a general block's form
sits on the General tab and its resolved props become `page.fields`, readable
by every other block through `$ctx->field()`; it has no React component —
the registry emitter skips it and the block picker never offers it.
`PageObserver::saving` adds the
missing rows, so seeders and the API get them too. A type switch leaves the
old type's rows dormant in `content` — hidden in the editor, skipped on the
site, carried through saves — and the General tab offers to delete them; a
switch back wakes them with their data.

The editor's type pick is two-step: "Type" (page / category / article and
any other top-level type) and, for a page, "Subtype" — the host types whose
`base()` is `page`. `PageSubtype` folds the two into the one stored key on
save and splits it on load, so nothing keyed on `type` changes. Category and
article subtypes are deliberately not offered yet.

Rejected alternative: a separate `fields` JSON column for the type's fields.
It would need its own form, its own revision handling and its own place in
the frontend props; a general block reuses the block form DSL, the envelope,
the revisions and the resolver as they are, and a content block of the type
interleaves with free blocks by `sort` without any extra ordering model.

Tests: `tests/Page/PageSubtypeTest.php` covers the option split, create via subtype, the ignored subtype under a non-page type and the load mapping. `tests/Page/TypeBlocksTest.php` drives the feature through the real
routes — creation on save, the General-tab form, the save round-trip, the
missing remove action, dormant rows on a type switch and their wake-up, the
purge action, and the frontend render with and without the type. Fixture
`ContactsPageType` is new.

**Read by eye:**
- `src/Page/Contracts/PageTypeInterface.php`
- `src/Page/TypeBlock.php`
- `src/Page/TypeBlocks.php`
- `src/Page/PageSubtype.php`
- `src/Admin/Pages/Support/PageFormOptions.php`
- `src/Admin/Pages/Support/PageEditSidebar.php`
- `src/Page/Observers/PageObserver.php`
- `src/Admin/Fields/BlockContent.php`
- `src/Admin/Pages/Support/PageEditBlocks.php`
- `src/Admin/Pages/Support/PageEditTypeFields.php`
- `src/Block/BlockContentResolver.php`
- `src/Block/ResolveContext.php`
- `src/Frontend/Http/Controllers/PageController.php`
- `src/Console/BlocksTypesCommand.php`

**Assumptions:**
- A type declares each kind at most once; a second declaration of the same kind is treated as present after the first row exists.
- Dormant rows are never cleaned automatically; a page that cycles through many types keeps one set per type until an editor deletes them.
- A dormant row that points at a shared block still counts in that block's usage and still blocks its deletion.
