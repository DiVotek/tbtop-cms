# feat(node): source-backed page types, stored templates, per-request SEO

touches: @tbtop/cms `PageTypeDef` (sourceBacked, source, sourceLinks, requestSeo), `ResolveContext` (source, url), `site.pages` (ensureSource, setSourceStatus, deleteSource, findBySource, allBySource), `site.templates`, exports `blockEditor`, `templateEditor`, `PageTemplates`, `Pages`, `PageRuleError`; README Page types

A page type may mirror records another system keeps (a shop's products and
collections). Its pages are made from code by `(type, source_id)`, once,
with their slug and parent; a page whose source is gone answers not found.
Every page of such a type renders the type's template, which a host edits
on its own admin page with the CMS's block editor (`templateEditor`, stored
in Settings). A page type may set `noindex` and the canonical per request
(a filtered listing). The admin's New page leaves source-backed types out,
and their editor shows a Source card instead of blocks.

Upgrade: nothing to change, unless host code builds a `ResolveContext`
literal by hand (a test): it now needs `source` and `url` (null for both
outside a page render).
