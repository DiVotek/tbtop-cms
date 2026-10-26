# feat(pages): the page title moves to the SEO tab's meta title

touches: page editor General tab, page create dialog, page save, `page.title` prop (deprecated)

> ⚠️ **Migration** `database/migrations/2026_10_26_000000_copy_page_title_to_seo_title.php` — per locale, copies a non-blank `title` into `seo.title` where that locale's `seo.title` is empty (a legacy scalar `seo.title` counts as filled for every locale); never overwrites a filled one; soft-deleted pages included; chunked raw rows, JSON decoded in PHP (SQLite and MySQL alike); rerun is a no-op. `down()` is a no-op: a copy renders the same `<title>` as the `title` it came from.
> ⚠️ **Deprecated** `page.title` (prop, column, model attribute) is no longer editable in the admin and stays only so a host reading or writing it keeps working; it is still in the meta-title cascade (`seo.title` → `title` → `name`). To be removed in a later release.

"SEO in SEO, content in content": a page had `title` on the General tab and
`seo.title` on the SEO tab, and both only fed `<title>`. The General tab and
the create dialog drop the *Title* field; the meta title is edited only on the
SEO tab. `heading` (the H1) is unchanged. Saving a page no longer writes
`title`, so a stored value survives every save, and a new page no longer gets
`title` filled from its name — that copy would have frozen the old name into
`<title>` after a rename, with no field left to fix it.

Left reading `title` as before: the meta-title cascade (`PageOwnSeo`),
breadcrumb and JSON-LD labels, `SeoAudit`'s report labels and
`missing_translation` check, the pages-list SEO badge, revisions (snapshot and
restore), the duplicator, `PageSeeder` (seeds the system pages' `title` from
`tbtop-cms.pages.*.title`) and the generated page prop types (`title`
nullable).

Tests: `tests/Page/PageTitleToSeoMigrationTest.php` (per-locale copy, filled
and legacy-scalar `seo.title` kept, trashed pages, rerun; `<title>` unchanged
per locale after the copy), `tests/Admin/PageEditPageTest.php` (no `title` on
the General tab; a save keeps a stored title), `tests/Admin/PagesCreatePageTest.php`
(no `title` in the create dialog; a new page stores none and its meta title
follows a rename).

**Read by eye:**
- `database/migrations/2026_10_26_000000_copy_page_title_to_seo_title.php`
- `src/Admin/Pages/Support/PageFormAttributes.php`

**Assumptions:**
- No host relies on a new page's `page.title` being filled from its name.
