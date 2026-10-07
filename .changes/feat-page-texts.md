# feat(blocks): page texts for blocks and the frontend with locale fallback

touches: ResolveContext, page props, PageTranslation

> ⚠️ **Contract** `page.body` now ships in the `page` prop as the Body rendered to HTML (`''` when empty), in the active locale falling back to the default language.

`ResolveContext::pageText(string $field): ?string` returns the page's
`name`, `title`, `heading` or `summary` in the active locale, falling back to
the default locale; `null` when neither is filled or there is no page.
`ResolveContext::pageBodyHtml(): string` does the same for the Body, rendered
to HTML. A block that echoes the page's own texts uses these instead of
reading `$ctx->page->getTranslation()`.

`Page\Support\PageTranslation::get()` is the shared reader: a translation
saved as `''` (the editor stores one for every locale it showed) or an empty
richtext document counts as missing, so it no longer shadows the
default-language text.

Tests: `tests/Block/BlockContentResolverTest.php` (`pageText()` and
`pageBodyHtml()` from a block, via the `tests/Fixtures/PageTextBlock.php`
fixture), `tests/Frontend/PageRenderTest.php` (`page.body` in the page prop,
with the fallback).

**Read by eye:**
- `src/Page/Support/PageTranslation.php`
- `src/Block/ResolveContext.php`
- `src/Frontend/Http/Controllers/PageController.php`

**Assumptions:**
- The fallback is the site's default language, not `app.fallback_locale`.
