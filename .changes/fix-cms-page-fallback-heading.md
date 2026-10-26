# frontend: the fallback error page shows its heading

touches: `resources/js/cms/CmsPage.tsx` (the reference component hosts copy)

A 404 or 500 with no published error page in the database answers with an
empty block spec and only `page.heading` set, and the reference `CmsPage`
rendered nothing, so the page was blank (seen in the workbench). It now renders
that heading as an `<h1>` when `page.id` is `null`. A host with its own copy of
`CmsPage.tsx` adds the same branch.
