# fix: final review of next — retyped reference targets, shared blocks under Octane, fallback page.body, upgrade notes

touches: pages field save rule, block content resolver, fallback error page props, UPGRADING.md

> ⚠️ **Contract** The `page_ref` save rule no longer checks a pages field's `of()` filter: it rejects only an id that does not exist or is the page being edited. `of()` is enforced by the admin search. `PageRefRule::forFilter()` is replaced by `PageRefRule::except()`.
> ⚠️ **Contract** `BlockContentResolver`'s constructor no longer takes `SharedBlocks`; it reads the request-scoped memo from the container per call.

- **Retyped targets.** Changing the subtype of a page that others reference
  (or a leaf flipping type with its category) made every referencing page
  fail validation on its next save, while the site kept listing the
  reference. Stored references now keep saving.
- **Octane.** The route-cached controller held the first request's
  shared-block memo, so shared-block edits never reached the site under
  Octane. php-fpm was not affected.
- **Fallback error page.** `page.body` is now present (`''`), as
  inventory.md documents.
- **UPGRADING.md.** Fixed the prefix migration's filename, the subtype
  rollback (pages do move back), the pages-list tabs (one per type; old
  `?tab=` values and lang keys removed) and the resolver's dependencies.
