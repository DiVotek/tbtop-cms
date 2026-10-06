# fix(pages): the parent picker follows the type registry

touches: page create form, page edit sidebar (parent picker)

The create form showed the Parent field only for `category` and `article`,
and the picker offered only `category` pages — both hard-coded. A host whose
category subclass accepts plain pages (`allowedChildren(): ['page', 'category']`)
could not create a page under it from the admin: the field was hidden for
`type = page`, and the sidebar picker would not list a non-category parent.

Now `PageForm::childTypes()` is every type key some registered type accepts
as a child, and `parentQuery()` offers every page whose type accepts
children. With the stock registry both are unchanged
(`['category', 'article']` / categories only).

Tests: `PagesCreatePageTest` "offers a parent to a plain page once a host
category accepts pages" (red before: the field was hidden for `page`).

Read by eye: a host that binds a category subclass accepting `page` and
creates a page under it from the create modal.

Assumptions: the "Categories" bucket of the pages list and the starter-block
tabs keep keying on the `category` type key; a host subclass keeps the key.
