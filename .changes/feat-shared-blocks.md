# feat(blocks): shared blocks — edit once, place on many pages

touches: block resolve, page editor, admin nav, content cache, migrations

> ⚠️ **Migration** `database/migrations/2026_10_03_000002_create_shared_blocks_table.php` — creates `tbtop_shared_blocks` (`name`, `kind`, json `data`); guarded no-op if the table exists.
> ⚠️ **Content shape** a page (or layout) content row may now carry a top-level `shared: <int>` next to `kind`; such a row's `data` is `[]` and the resolver reads the shared block's `data` instead. A host that reads `content` rows directly (seeders, exports, its own resolver) must honour the pointer or it sees empty blocks.
> ⚠️ **Constructor change** `BlockContentResolver` gains a `SharedBlocks` dependency — resolve it from the container, not with `new`.

A block whose content repeats across pages (a CTA, a contact strip) is
created once under Design → Shared blocks (a card grid like Blocks, a tab
per kind as the type filter) with a name and a kind, filled in on its own
edit page (the block's normal form), and placed on pages from the "+ Add
block" tab: the Add button of a kind that has shared blocks opens a chooser
— one of them by reference, or a fresh inline block.
The page editor shows a reference as a tab without fields — a link to the
shared editor, active/order, Detach (copies the content into the page and
drops the pointer) and Remove; an inline block's tab has Make shared, the
reverse move, which names the new shared block after the block and page. A shared block pages still place offers no
delete; its card shows the page count. A pointer to a deleted shared block, or one of
another kind, is skipped on the site and listed under Problem blocks in the
editor; its row stays in the content. Saving or deleting a shared block bumps the content cache.

**Read by eye:**
- `database/migrations/2026_10_03_000002_create_shared_blocks_table.php`
- `src/Block/BlockContentResolver.php`
- `src/Admin/Fields/BlockContent.php`
- `src/Admin/Pages/Support/PageEditBlockTabs.php`
- `src/Admin/Pages/Support/SharedBlockForm.php`
- `src/SharedBlock/SharedBlockUsage.php`

**Assumptions:**
- Layout slots get the resolver side only; the layout editor offers no shared picker yet.
