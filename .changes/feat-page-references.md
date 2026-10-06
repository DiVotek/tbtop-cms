# feat(pages): a pages field and reverse lists of the pages that reference a page

touches: page type fields, block form fields, General tab, page save, page.fields, cms:install, PageTypeInterface

> ⚠️ **Contract** `PageTypeInterface::backReferences(): array<string, array{field: string, from?: string}>` is new (`BasePageType` returns `[]`). A host type implementing the interface directly must add it.
> ⚠️ **Contract** New table `tbtop_page_refs` (`page_id` FK cascading, `field`, `target_id`, `position`): a derived index of page-type `pages` fields, rewritten on every page save. Run `php artisan cms:page-refs:rebuild` once after upgrading, or a reverse list stays empty until each source page is saved again.
> ⚠️ **Contract** New field kind `$s->pages('name')`: an async multiple `select` on the wire that stores page ids as strings, validated by the new `page_ref` rule; resolves to `list<{id, name, url}>` of live pages, and `cms:blocks:types` types it so.

An editor picks several pages in a type field or a block field —
filtered by `->of(type, subtype)`, drafts selectable, the edited page left
out — and the site gets the live ones as `{id, name, url}` in the picked
order and the request locale. A page type declares `backReferences()` to
receive the reverse list: the live pages whose field X picks it, optionally
only from one type or subtype. `PrefixedField::rename()` now rebuilds a
field from its own class rather than its wire kind, so a field that
serializes as a built-in kind keeps its server-side closures.

Tests: `PageRefsTest` (6; each rule mutated by hand and caught — stored
order, live filter, locale url, `of()` on save, search filter and
self-exclusion, rewrite on update, `from` filter, sort order, rebuild),
`FormResolverTest` covers the pages kind and its PHP type.

Read by eye: on a subtype with a pages field, the General tab shows a
searchable multi-select of pages; picked pages keep their labels after
reload.
