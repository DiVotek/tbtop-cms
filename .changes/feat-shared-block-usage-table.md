# feat(blocks): shared block placements as a table with open and detach

touches: shared blocks edit page

The shared block's edit page lists its placements as a table instead of
a list of links: one row per page or layout slot, with a type badge, an
open button to that editor, and a detach button that copies the block's
content into that container and drops the pointer — so a page gets its
own copy without opening it. `SharedBlockDetacher` carries the move; a
page save goes through `PageObserver`, so an editor with the page open
meets the usual lock conflict rather than a silently re-pointed row.
