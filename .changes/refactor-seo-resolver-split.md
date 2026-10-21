# refactor: split SeoResolver's templating and hreflang into their own classes

touches: nothing visible; `seo` props, rendered titles and descriptions unchanged

`SeoResolver` (591 lines) now delegates two concerns: `SeoTextRenderer`
applies the title/description templates (`{title}`, `{sep}`, `{parent}`,
`{date}`, the legacy suffix, separator cleanup) and `SeoAlternates` builds the
hreflang list, x-default and og:locale alternates. Its public methods
(`resolve`, `renderedTitle`, `renderedDescription`, `validCanonicalOverride`,
`ownImage`) and constructor are unchanged.

Second of five splits from arti `spec-cms-tbtop-split-large-classes`.
