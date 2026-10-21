# refactor(pages): Cms::referencedBy() replaces backReferences()

touches: PageTypeInterface, page.fields, cms:doctor, cms:blocks:types, Cms facade

> ⚠️ **Contract** `PageTypeInterface::backReferences()` is removed (and `BasePageType`'s default). A host type that declares it moves the entry into its `resolveFields()`: `'specialists' => Cms::referencedByLinks($ctx->page, 'services', 'specialist')` gives the same list under the same key.
> ⚠️ **Contract** `cms:doctor` no longer runs `page_field_collisions`; its `tbtop-cms::health.checks.page_field_collisions.*` translation keys are gone.

The package no longer writes reverse lists into `page.fields` after the
type's `resolveFields()`. A computed key named like a `backReferences()` key
was silently replaced by the plain `{id, name, url}` list, and `cms:doctor`
could not see computed keys. The type now asks for the referencing pages
itself: `Cms::referencedBy($page, $field, $from)` returns the live `Page`
models (by `sort_order`, then name), `Cms::referencedByLinks()` the
`{id, name, url}` list. `page.fields` has one writer, so nothing can
overwrite a computed value. The `pages` field and the `tbtop_page_refs`
index are unchanged. `cms:blocks:types` types the key from the override's
`@return array{...}` like any computed key.

Tests: `PageRefsTest` reads the reverse lists through a fixture type that
builds both links and its own entries from `referencedBy()`; the
`page_field_collisions` cases and the `ShadowingSubtypePageType` fixture go.
