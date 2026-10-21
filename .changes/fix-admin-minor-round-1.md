# fix: admin errors, labels and links (minor-fix round 1)

touches: Languages, Pages list, shared-block usage, audit log, BlockContent field, redirects, 404 log

- Languages: an observer guard (unsetting the only default, frontend without admin) or a validation error keeps the modal open with its message instead of a 500 or a silent 422; a stale delete of the default shows an error notice.
- Pages list: Duplicate on a page whose type/subtype is no longer registered shows an error notice instead of a 500; "View on site" is hidden for rows with no path (error pages); the type column uses the type's `label()` like its tab.
- Shared block usage: a trashed page's row has no editor link (it 404'd).
- Audit log: shared-block entries get a type label and a filter option (`audit.types.shared_block`).
- `BlockContent::make()`: the kind select shows each block's label, not its key.
- Redirects: `/` is accepted as a target (the homepage); a blank target is reported on the target field; loop and chain-length errors are translated (`redirects.validation.loop`, `chain_too_long`).
- 404 log: creating a redirect for an already-ignored hit no longer logs a second "ignored".
