# feat(pages): a leaf's type follows its category

touches: page create form, page edit form (save buttons, type/parent), pages list tabs, subtype on type change

> ⚠️ **Contract** Saving a page with a category makes it an `article` (only a `category` parent does this — a page under a host container that allows pages stays a page); saving an article without one makes it a `page`. Picking `page` drops any parent; picking `article` requires one (`parent_id` error `pages.validation.article_needs_category`). The article rules hold only while the registered `article` type `requiresParent()`.
> ⚠️ **Contract** A type change that would leave the page's stored subtype behind is rejected on `type` (`pages.validation.type_change_subtype`); it used to drop the subtype silently.
> ⚠️ **Contract** The pages list has one tab per `type` a live page has (named by the key, `?tab=page` / `?tab=article` / `?tab=section`, labelled by the type), between "All" and "Deleted"; the fixed "Categories" / "Pages" / "Articles" tabs (`?tab=categories|pages|articles`, which used to mean "no parent" / "has a parent") and their `pages.tabs.*` lang keys are gone.

The stock tree already forbids a page under a category and an article
without one, so a save that broke the rule bounced off PageHierarchy with a
bare error. The forms now apply the rule: give a page a category and it
becomes an article, take an article's category away and it becomes a page.
The edit form swaps its save buttons for confirming ones while the type
would change, with copy that says what changes (schema, type fields, tab,
address with a redirect).

The create form shows the category picker for an article (required) and a
category (optional), labelled "Category" — a parent is always one.

Tests: `PageLeafTypeTest` (6 of 7 red before; plus a page under a host
`section` that stays a page), `PageSubtypeTest` updated for
the refusal, `PageEditPageTest` footer lists the confirming twin.

Read by eye: the confirm dialog on both save buttons when a page gets a
category in the sidebar, and when an article's category is cleared.

`mod+s` opens the confirm while the type would change (it sits on both
footer save twins). Both article rules apply only while the `article` type
`requiresParent()`; a host override returning `false` keeps root articles.
A legacy page already under a category gets the confirm on any save. After
a Type change the Subtype select stays until the stored subtype is cleared.
