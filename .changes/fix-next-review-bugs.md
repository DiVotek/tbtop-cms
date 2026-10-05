# fix(blocks): review fixes for type blocks, shared blocks and the block registry

touches: page type blocks, block picker, blocks:types registry emitter, block content resolver, shared blocks (layout save, form submit, usage count), frontend renderer, CI

> ⚠️ **Contract** a kind declared `TypeBlock::general()` is no longer hidden everywhere: pickers on other types offer it and `cms:blocks:types` requires its component. A fields-only block returns `[]` from `Block::placements()` to stay out of both.
> ⚠️ **Contract** a content row `TypeBlocks::ensure()` adds to an existing page is created inactive (was active). New pages and general rows are unchanged.
> ⚠️ **Contract** `tbtop-cms.blocks.registry_path` accepts `false`: no registry file is written or checked.

Bugs found reviewing #233–#242, each small and closed on its own:

- "General" was a global property of a kind: one type declaring `Hero` as
  general removed `Hero` from "+ Add block" and from the generated registry
  for every page. It is now a role on the declaring type; the block opts out
  globally through `placements(): []`. The registry asks for a component
  exactly where a block is drawn: placeable somewhere, or carried as content
  by a type (`TypeBlocks::contentKinds()` replaces `generalKinds()`).
- Assigning a type to a live page (or adding a block to a type) put an empty
  content block on the public page at the next save. It now starts inactive.
- A general block resolving `null` for a key wiped the value an earlier
  general block had filled in `page.fields`.
- Saving a layout slot dropped the `shared` pointer of its rows, turning a
  reference into an empty inline block.
- A form placed by reference was submitted against the empty row, not the
  shared block's settings. `SharedBlocks::dataOf()` is now the one place that
  answers "what data does this row carry", used by render and submit.
- The page editor decoded every page and layout to print usage counts on
  each render. `SharedBlockUsage::counts()` reads one column per table.
- A block hidden for a broken resolve or a missing component left no trace
  outside preview; the renderer now logs it to the console (and the SSR log).
- `composer types:blocks:check` failed on the package itself, which keeps a
  handwritten registry; the generator script sets `registry_path => false`.
- CI ran only for `main`; pull requests into `next` now run it too.

Rejected alternative: a database `LIKE`/JSON query for usage counts. It is
cheaper still, but JSON escaping differs between SQLite and MySQL and the
count must match what `refsIn()` sees.

Tests: `tests/Page/TypeBlocksTest.php` (inactive row on an existing page,
per-type picker, null-safe fields), `tests/BlockTypes/BlocksTypesCommandRegistryTest.php`
(fields-only block skipped, placeable general kind needs its component,
`registry_path => false`), `tests/SharedBlock/SharedBlockAdminTest.php`
(layout save keeps the pointer, chooser counts), `tests/Frontend/FormSubmitTest.php`
(form by reference), `resources/js/cms/CmsRenderer.test.tsx` (console line).
Fixtures `FieldsOnlyBlock` and `FieldsOnlyPageType` are new.

**Read by eye:**
- `src/Page/TypeBlocks.php`
- `src/Admin/Pages/Support/PageEditBlockTabs.php`
- `src/Console/BlocksTypesCommand.php`
- `src/Block/BlockContentResolver.php`
- `src/SharedBlock/SharedBlocks.php`
- `src/SharedBlock/SharedBlockUsage.php`
- `src/Admin/Pages/Support/LayoutEditBlocks.php`
- `src/Form/Support/FormBlockLocator.php`

**Assumptions:**
- A host block that is fields-only gains `placements(): []` when it takes this version.
- Existing pages keep the rows they have; nothing is switched off retroactively.

**Left for the design discussion:** a type with a `base()` other than `page`
appears in neither select and a save changes its type; type fields as a
`fields()` method instead of general blocks; a single source for contacts;
blank-aware translation fallback.
