# fix(node): require @tbtop/inertia-admin ^0.7.3 for the page editor's Revisions badge

touches: @tbtop/cms peer range for @tbtop/inertia-admin (^0.7.1 → ^0.7.3); workbench pin

On 0.7.1/0.7.2 the Revisions action's count badge on the page editor was not
anchored to its button: it sat off-screen in the top-right corner and widened
the page by 6px, a horizontal scrollbar. tbtop#402 fixed it in the client and
shipped in 0.7.3; the PHP package already requires `tbtop/admin` ^0.7.3.

Upgrade: a host on `@tbtop/inertia-admin` 0.7.1 or 0.7.2 now gets an unmet
peer warning (or an install error with strict peers) until it moves to 0.7.3.
