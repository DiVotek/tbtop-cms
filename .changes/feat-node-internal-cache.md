# feat(node): cache the frontend's internal reads as PHP does

touches: @tbtop/cms `cache` (what it covers, when it bumps), README Caching section

With `cache: { store }` the node package now keeps, besides settings,
languages and collections, the header and footer per origin and locale, the sitemap
(index, chunk count and chunks per origin) and llms.txt per origin and
locale, as `CmsCache` does in PHP. Every write through the CMS bumps the
content version from one place (`AuditLog.track`): pages, layouts,
redirects and shared blocks now bump too, where only settings, languages
and entries did. An entry never outlives the nearest `published_at` or
`unpublish_at` of a published page (`rememberLive`), so a scheduled page
shows without a write or a cron (on KV up to a minute late, its shortest TTL). The store is still written only
on a miss or a bump, and an edit now costs one bump instead of two.

The rendered HTML stays the host's to cache: the README has a Workers
recipe.

Upgrade: nothing to change. A host that wrote content around the CMS and
relied on the header or sitemap reading the database on every request
now sees those changes once the entry expires (`ttl`).
