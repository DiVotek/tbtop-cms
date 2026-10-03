# feat(blocks): a broken block shows a placeholder instead of vanishing

touches: block resolve, page spec, frontend renderer, cms:doctor, cms:install

> ⚠️ **Contract change** `BlockContentResolver` — a block whose kind is not registered or whose `resolve()` throws is no longer dropped from the list (non-strict mode): it stays with empty props and `ResolvedBlock::$error` set, `ResolvedBlock::toArray()` carries `error`, and `PageSpecAssembler::assemble()` returns `errors: {[id]: message}` next to `root`/`elements`. A host counting resolved blocks or asserting a thrown block is absent sees it present.
> ⚠️ **Contract change** `@cms/CmsPage` renders through the new `@cms/CmsRenderer`; a host rendering with `@json-render/react`'s bare `<Renderer>` keeps working but gets a `cms:doctor` warning, because that renderer drops a broken block with only a console line.

A block the server could not resolve, a kind with no component, props that
fail the catalog schema, or a component that throws: each becomes a
placeholder in the block's place — a red box naming the block and the
reason in preview and `app.debug` (`cms.debug` is now shared), nothing for a
visitor. `cms:install` lists the renderer as a wiring step.
