# feat(media): responsive image descriptor for blocks

touches: block resolve context, media lookup, host block docs

`ResolveContext::mediaImage($id)` (backed by `MediaUrlResolver::image()`)
returns what an `<img>` needs for a library media id:
`{src, srcset, width, height, alt}`. `src` is the original; `srcset` lists the
variants tbtop/admin stores in the media item's `sizes`, narrowest first, then
the original at its own width. A variant is listed only when it keeps the
original's aspect ratio (within the fit-inside rounding) and is narrower than
the original, so a cropped variant never enters the srcset. `srcset` is `null`
when no variant qualifies or the original's width is unknown; a missing or
pathless id returns `null`, like `mediaUrl()`.

Nothing changes for existing blocks: a form-resolved `media` field still ships
a URL string, and `mediaUrl()` is untouched. With tbtop/admin 0.7.1 defaults a
media item has only `thumb` (fit inside 320×320), so the srcset is that one
small candidate plus the original; the `sm`/`md`/`lg` webp variants arrive with
the tbtop/admin release that ships them and its `tbtop:media-regenerate`.
