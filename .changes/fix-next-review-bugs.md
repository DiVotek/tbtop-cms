# fix(blocks): review fixes for shared blocks and the block registry

touches: block content resolver, shared blocks (layout save, form submit, usage count), frontend renderer, blocks:types registry emitter, CI

> ⚠️ **Contract** `tbtop-cms.blocks.registry_path` accepts `false`: no registry file is written or checked.

Bugs found reviewing #233–#242, each small and closed on its own:

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

Tests: `tests/BlockTypes/BlocksTypesCommandRegistryTest.php`
(`registry_path => false`), `tests/SharedBlock/SharedBlockAdminTest.php`
(layout save keeps the pointer, chooser counts), `tests/Frontend/FormSubmitTest.php`
(form by reference), `resources/js/cms/CmsRenderer.test.tsx` (console line).

**Read by eye:**
- `src/Block/BlockContentResolver.php`
- `src/SharedBlock/SharedBlocks.php`
- `src/SharedBlock/SharedBlockUsage.php`
- `src/Admin/Pages/Support/LayoutEditBlocks.php`
- `src/Form/Support/FormBlockLocator.php`

**Assumptions:**
- Existing layouts keep the rows they have; a pointer already lost is not restored.
