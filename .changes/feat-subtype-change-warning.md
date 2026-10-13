# feat(pages): warn before a subtype change strands references to the page

touches: page editor Save (admin)

Picking a subtype in the page editor that a referencing pages field does not
accept (its `of()`) now swaps Save for a confirming twin that lists those
pages (up to ten, the rest counted). Found through `tbtop_page_refs`; drafts
count, trashed referrers do not, and a field that already rejects the stored
subtype is not listed. The references stay valid (`page_ref` checks only
existence) and the site keeps listing the page; only the field's search
stops offering it. A leaf-type flip confirm wins when both apply. Automatic
retyping without the form (#250/#254) is not covered.

Tests: `tests/Admin/PageSubtypeChangeTest.php`, `tests/Page/PageTypeFilterTest.php`
(`accepts()` agrees with the search query).
