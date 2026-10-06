# feat(blocks): rows that cannot render are skipped on the site and listed in the editor

touches: block resolve, page spec, CmsRenderer, page editor, layout editor, cms:doctor, docs

> ⚠️ **Contract** A content row whose kind is unregistered or whose `shared` pointer is missing or of another kind is skipped by the site — no element, no `children` entry, no log line, preview included. A `resolve()` that throws is dropped too (still logged as `block.resolve_failed`). `blocks.strict` throws as before.
> ⚠️ **Contract** `render` is `{root, elements}`: `render.errors`, the `CmsBlockError` element and `PageSpecAssembler::BLOCK_ERROR_KIND` are gone (unreleased, from #239/#246). `ResolvedBlock` loses `error`, so `cms.layout` items are `{id, kind, props}`.
> ⚠️ **Contract** `@cms/CmsRenderer` draws no placeholder: a block with no component, props failing the schema or a throwing component renders nothing; `showErrors` only gates a developer `console.error` line. `BlockErrorPlaceholder` is gone; `@cms/blockErrors` exports `dropBrokenBlocks()` instead of `markBrokenBlocks()`/`BLOCK_ERROR_KIND`.
> ⚠️ **Contract** Such a row no longer gets a tab in the page or layout editor; both show "Problem blocks (N)" (`pageProblemBlocks`/`layoutProblemBlocks`) with the reason, the saved data and "Delete row" (`…ProblemDelete_{rowId}`). New doctor check `unregistered_block_rows`.

The rule is one enum, `Block\BlockRowProblem` (`unregistered`,
`shared_missing`, `shared_kind_mismatch`), read by the resolver, the
editor's `ProblemBlocks` list and both doctor checks, so the site never drops
a row the editor does not show. Delete row saves the editor's whole form
through the tab Remove's write (`PageEditBlockTabs::removeRow()`,
`LayoutEditBlockTabs::removeRow()`): lock_version is checked on a page, and a
stale form keeps the modal open (`haltModal`) and writes nothing.

The action sits inside the editor form (page: the status bar next to
Revisions; layout: above the tabs), not in `headerActions()`: only an action
in the form's subtree gets `payload.form`, and without the form the Remove
write would save every inline block with empty data.

**Rejected alternative:** a placeholder visible in preview (the #239
behaviour) — the owner wants editors, not visitors or previews, to see these
rows, and the editor is where they can act on them.

Tests: `tests/Frontend/PageRenderTest.php` (unregistered kind + dangling
shared ref: 200, neither row in `render`, no `errors` key, no log),
`tests/Block/BlockContentResolverTest.php` + `tests/SharedBlock/SharedBlockResolveTest.php`
(drops; strict unchanged), `tests/Block/ProblemBlocksAgreementTest.php`
(resolver and editor agree on every row), `tests/Admin/ProblemBlocksTest.php`
(count, no tab, reason and data in the modal, delete keeps the others, stale
form, clean page, layout slot), `tests/Health/BrokenSharedReferencesCheckTest.php`
(`unregistered_block_rows`), `resources/js/cms/CmsRenderer.test.tsx`
(nothing rendered, console only with `showErrors`), updated
`LayoutEditTabsTest`/`SharedBlockAdminTest` (no tab for problem rows).

**Read by eye:**
- `src/Block/BlockRowProblem.php`, `src/Block/BlockContentResolver.php`
- `src/Admin/Pages/Support/ProblemBlocksAction.php` (the `<pre>` goes through unsanitised `displayHtml`, escaped with `e()`)
- `src/Admin/Pages/Support/PageEditBlockTabs.php` (`removeRow()`, shared tab now always has its target)
- `resources/js/cms/CmsRenderer.tsx`, `resources/js/cms/blockErrors.ts`

**Assumptions:**
- An action inside a modal opened from an in-form action is sent the
  enclosing form on the client, as the existing "add shared block" chooser
  relies on. Not checked in a browser in this change.
- A source-backed page shows no Problem blocks action, like it shows no
  block tabs: its content belongs to its type's template.
- `.changes/feat-broken-block-placeholder.md` and the `CmsBlockError` part of
  `.changes/fix-review-batch.md` describe behaviour this change removes before
  any release; they are left for the release notes pass to fold.
