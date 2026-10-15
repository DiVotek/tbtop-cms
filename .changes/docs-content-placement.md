# docs(pages): where page content goes

touches: host-builder guide (README, new placement.md, pages, blocks, recipes)

Hosts modelled every fixed part of a page type as a block, and put the
page's description into a type field while `body` stayed empty, because the
guide offered fixed parts only through `template()` and never said the
standard page fields come first.

- `docs/host/ai/placement.md` (new, first in the guide's reading order): an
  ordered first-match rule — standard fields, type fields, `pages` fields /
  `backReferences()`, `resolveFields()`, a host frame by `page.subtype`,
  blocks with `placements()`, layout slot / shared block, and `template()`
  only for source-backed types — with why `template()` is not a frame (the
  admin always stores `content` on a content-only page), wrong placements
  seen in imports, and a fill-count check per type after modelling.
- `recipes.md`: "A page type with a fixed frame and a blocks slot",
  end to end against `resolveFields()`, `BackReferences`, `page.*` props and
  `CmsRenderer`.
- `blocks.md`: known limitation — a form must be a stored block
  (`FormBlockLocator` reads page `content` and layout slots only, not a
  frame and not `template()`), with the layout-slot receiver workaround.
- One-line pointers to placement.md from Starter blocks, Source-backed
  pages, Subtypes and type fields, Shared blocks and the related recipes;
  the stale "form only in `$page->content`" note in recipes is corrected.

- The admin hint for a page's `name` (en, uk) no longer says it is not shown
  on the site: breadcrumbs and page-link labels use it.

No code, contract or test change.
