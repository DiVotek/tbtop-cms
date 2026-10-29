# feat(pages): tags and category archives (A-thin)

touches: page type contract, Cms facade, pages field, pages list, page render (seo), sitemap, translations, docs/host/ai

> ⚠️ **Contract** `PageTypeInterface` gains `listedInAll(): bool` and `archiveOf(): ?string`; `BasePageType` returns `true` and `null`. A host implementing the interface directly breaks at load.

A tag is a page: a category subtype the articles pick in a `pages` field.
Five small additions make it an archive:

- `Cms::referencedByPaged()` / `referencedByLinksPaged()` and
  `childrenPaged()` / `childrenLinksPaged()` give one page of live pages,
  newest first by `published_at`, with `{page, pages, total, perPage}`. The
  page number is the request's `?page=`. From page 2 on the canonical and
  `og:url` carry `?page=N`; a page past the last is a 404. One paged archive
  per page.
- `$s->pages()->of(type, subtype)->creatable()` lets the editor type a new
  page into the select: published, under the parent of the newest page of
  that type. With no page of the type yet the select answers 422
  (`pages.refs.create_needs_parent`).
- `listedInAll()` false keeps a type off the Pages "All" tab; an unlisted
  subtype gets a tab of its own and leaves its base's tab.
- `archiveOf()` names the `pages` field a type is an archive of: while no
  live page picks it through that field, it is noindex and left out of the
  sitemap.
- Recipe: recipes.md, "Tags and category archives".
