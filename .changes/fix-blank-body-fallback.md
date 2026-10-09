# fix(pages): an erased Body falls back to the default locale

touches: page props `page.body`, `$ctx->pageText()`

Lexical keeps one empty paragraph after its text is erased, so a Body typed
and then cleared in one locale counted as filled and shadowed the default
locale. A document of paragraphs holding only whitespace text and line
breaks now counts as blank; any other node (image, list, heading) is content.

Tests: `tests/Frontend/PageRenderTest.php` (erased Body falls back).
