# feat(pages): subtypes choose their schema, appear in the create form, and categories default their articles' subtype

touches: schema selection, SEO tab schema select, page create form, page save, PageTypeInterface

> ⚠️ **Contract** `PageTypeInterface::childSubtype(): ?string` is new (`BasePageType` returns `null`). A host type implementing the interface directly must add it.
> ⚠️ **Contract** A page publishes its subtype's `schemaTypes()` when the subtype is registered, else its base type's (`PageTypeRegistry::schemaTypesFor()`). A subtype extending `BasePageType` with `base() = 'article'` now publishes `WebPage`-family schema instead of `Article`; extend `ArticleType` to keep `Article`.
> ⚠️ **Contract** The create form names its subtype selects `subtype_{base}` (one per base with subtypes, shown for that base); save reads the picked type's one. A bare `subtype` key is still accepted.

A legal service under a "Services" category is a `Service`, not an
`Article`: the subtype is the more specific declaration, so its schema list
wins. The create form used to offer subtypes only for plain pages; it now
offers them for whichever type is picked, articles included.

A category (or its subtype — the subtype wins) may name the subtype its new
articles get: an article without a subtype takes it when created under the
category or when a page becomes an article there. An existing article is
left as it is, and an explicit clear in the editor still clears.

Tests: `SubtypeSchemaAndDefaultsTest` (4; each rule mutated by hand and
caught), `PageSubtypeTest` posts the per-base select.

Read by eye: the create modal shows "Subtype" after picking "Article" when
article subtypes exist.
