# refactor: split PagesPage's list state and row actions into their own classes

touches: nothing visible; the pages list, its badges, tooltips and row actions are unchanged

`PagesPage` (612 lines) now delegates two concerns: `PageListState` holds the
`display_state` and `seo_state` SQL together with the SEO badge tooltip (they
read the same facts and must stay in step), and `PagesRowActions` holds view on
site, duplicate, restore and force delete. `view()` is split into `columns()`,
`tabs()` and `filters()`.

Fourth of five splits from arti `spec-cms-tbtop-split-large-classes`.
