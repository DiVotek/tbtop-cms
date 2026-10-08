# fix: "Edit shared block" only for editors with the design ability

touches: page editor block tabs (shared-block reference tab)

With `tbtop-cms.roles` on, an editor holding `pages` but not `design` saw
"Edit shared block" on a reference tab and landed on a 403: the shared-block
edit page requires `design`. The link now renders only when the design gate
allows it, the same check "Make shared" already used. Detach, Active, Sort and
Remove stay for every page editor: detaching copies content into the page, so
it is a page edit, not a design edit.
