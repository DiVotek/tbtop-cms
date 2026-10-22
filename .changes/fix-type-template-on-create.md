# fix: a page created in the admin starts with its type's template() blocks

touches: page create modal; `PageTypeInterface::template()` for content-only types

`template()` rendered only for a null `content`, but the admin create wrote
`[]`, so an admin-made page never showed its type's default blocks. A new
page now gets a copy of `template()` in `content` when it inherits no
category starter blocks (`TypeTemplateContent`): the editor shows the blocks
and every save keeps them. Each copied block gets a fresh id; `active`,
`sort` and `data` may be left out. Seeded pages are unchanged: they render the template while
`content` is null and lose it on the first editor save, so seeders write
`content` themselves.
