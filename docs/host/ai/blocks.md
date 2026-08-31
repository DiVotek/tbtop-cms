# Blocks

> Part of the host-builder guide. Start at [./README.md](./README.md).

This file owns the whole block system: the PHP `Block` class, the resolve
pipeline, the form DSL as it applies to blocks, form-handling blocks, and the
client-side catalog/registry. Page types, content seeding, and the layout
editor UI are [./pages.md](./pages.md)'s job; block-derived SEO/JSON-LD is
[./seo.md](./seo.md); the notification fan-out for a submission is
[./notifications.md](./notifications.md) — one pointer below.

## The three-layer rule

A block is three things, in three places, kept in sync by hand:

| Layer | Where | Owns |
|---|---|---|
| PHP class | extends `Tbtop\Cms\Block\Block`, registered in `tbtop-cms.blocks.types` (or `BlockRegistry::extend()` for programmatic registration) | `kind()`, the editor form, `resolve()` — untrusted input to typed props |
| Zod schema | host's `resources/js/cms/catalog.ts`, keyed by `kind()` under `components` | the prop *contract* the renderer validates against |
| React component | host's `resources/js/cms/registry.tsx`, keyed by the same `kind()` | presentation |

They match by string identity: PHP `kind()` == the catalog's component key
== the registry's component key. Two of three wired is a broken block —
nothing enforces the tie at compile time. The one enforcement that does
exist is runtime: `@json-render/react`'s `Renderer` validates each element's
`props` against the catalog schema for its `type` before handing them to the
component, so a `resolve()` shape that drifts from the zod schema fails
loudly in the browser console, not silently. Keep `resolve()`'s return shape
and the zod object in lockstep by hand — nothing generates one from the
other.

## `Block` API

`src/Block/Block.php`. Every block extends this class and implements two
abstract methods:

- **`kind(): string`** — the wire identifier stored in the envelope and
  matched against the catalog/registry keys. Must be non-empty and unique
  across the registry (a duplicate kind throws `InvalidArgumentException`, see
  `tests/Block/BlockRegistryTest.php`).
- **`form(S $s): array`** — the editor form, in tbtop/admin's field DSL. See
  [Form DSL for blocks](#form-dsl-for-blocks) below.

Overridable, all with sane defaults:

- **`label(): string`** — showcase display name. Defaults to
  `Str::headline($this->kind())` (`"ContactForm"` → `"Contact Form"`).
- **`description(): ?string`** — one-line showcase blurb. `null` (default)
  hides the line.
- **`category(): ?string`** — showcase grouping bucket (e.g. `'content'`,
  `'forms'`). `null` (default) leaves the block ungrouped.
- **`resolve(array $input, ResolveContext $ctx): array`** — turns raw,
  already locale-flattened editor `data` into the props the React component
  receives. Default is identity (`return $input;`) — override this in almost
  every real block; the identity default only suits a block whose stored
  shape already matches its props 1:1.
- **`translatableFields(): array`** — dotted paths (e.g. `'items.caption'`)
  the flattener treats as locale maps. Default: walks `form()`'s field tree
  and collects every field marked `->translatable()`, at any repeater
  nesting depth (`Block::collectTranslatablePaths()`). Override only if a
  block's translatable set can't be read off its own form — the default is
  right for the overwhelming majority of blocks and is exercised directly by
  `tests/Block/TranslatableFieldsTest.php`.

## `ResolveContext`

`src/Block/ResolveContext.php`. A readonly value object, one instance per
`BlockContentResolver::resolve()` call, passed to every block's `resolve()`:

- **`locale`** / **`fallbackLocale`** — the flattener already used these to
  pick each translatable field's value before `resolve()` runs; a block only
  needs them again for locale-dependent logic that isn't just field
  flattening (e.g. picking a locale-specific default string).
- **`page`** — the `Page` model being rendered, or `null` when resolving
  outside a page context (a layout slot — `LayoutResolver` passes no page).
  Never assume non-null.
- **`source`** — the source-backed page type's domain object (e.g. a
  `Product` model), or `null` for a non-source-backed page/layout slot. Set
  by `PageController` only when the page's type is source-backed; see
  pages.md for what "source-backed" means. A block that reads `$ctx->source`
  is coupled to running only on that page type — check for `null` /
  `instanceof` before use.
- **`mediaUrl(string|int $id): ?string`** — resolves a stored media id
  (never store URLs directly in block `data`) to a public URL via
  `MediaUrlResolver`, `null` if the media row is missing or has no path.
  Call this from `resolve()`, never store the resolved URL back into the
  envelope.
- **`richtextHtml(mixed $value): string`** — renders a stored richtext value
  (from an `$s->richtext()` field) to safe HTML via `RichtextHtmlRenderer`.
  Always call this rather than passing the raw richtext value to props; the
  React side expects an HTML string, not the editor's internal document
  shape.

## Data flow

```
envelope {id, kind, data, sort, active}   (BlockEnvelope)
  → BlockContentResolver::resolve()
    → LocaleFlattener::flattenData()      (per-block, before resolve())
    → Block::resolve($flat, $ctx)
  → ResolvedBlock {id, kind, props}
  → PageSpecAssembler::assemble()
    → json-render spec {root: '$root', elements: {...}}
```

**Envelope** (`src/Block/BlockEnvelope.php`): the raw JSON stored in a
page's `content` column (or a layout slot's `content`, or
`starter_blocks[type]`), one array per block: `{id, kind, data, sort,
active}`. `BlockEnvelope::blocksOf()`/`starterOf()` are the shared guard used
by every reader that touches this column *without* going through
`BlockContentResolver` (form editors, starter-block sync) — they only
guarantee "a list of arrays," nothing about shape.

**`BlockContentResolver::resolve()`** (`src/Block/BlockContentResolver.php`)
is the single path from stored envelope to renderable blocks. **Hosts never
call it directly** — the package invokes it internally during page render
(via `PageSpecAssembler`); a block author only implements `Block::resolve()`
and receives already-flattened data (called during page and layout render). Per entry, in
order:

1. Sort by `sort` (missing `sort` — legacy content saved before the field
   existed — falls back to array index; PHP's stable `usort()` keeps
   original order among ties).
2. Skip if `active === false`, or if `kind`/`id` aren't strings.
3. Look up `kind` in `BlockRegistry`. Unknown kind: skipped (with a
   `block.unknown` warning log), *unless* `tbtop-cms.blocks.strict` is true,
   in which case it throws `BlockResolutionException::unknownKind()`.
4. Flatten `data` for the current locale via `LocaleFlattener`, using the
   block's `translatableFields()`.
5. Call `$block->resolve($flat, $ctx)`. An uncaught exception here is
   caught, logged as `block.resolve_failed`, and the block is skipped —
   again unless `blocks.strict` is true, which rethrows as
   `BlockResolutionException::resolveFailed()`. One broken block never takes
   down the rest of the page.

**`blocks.strict`** (`config/tbtop-cms.php`, default `false`): non-strict is
the resilient default for production frontend rendering (skip bad blocks,
log, move on). Flip it on for tests, CI, or admin preview flows where a
silently-dropped block is a bug you want to catch, not paper over. See
`tests/Block/BlockContentResolverTest.php` ("throws on an unknown kind when
strict mode is enabled", "throws with the original error as previous when
strict mode is enabled").

**Locale storage and flattening**: a translatable field is stored as
`{"uk": "...", "en": "..."}`. `Tbtop\Cms\Block\Support\LocaleFlattener`
(`src/Block/Support/LocaleFlattener.php`) walks the block's `data` tree and,
at each dotted path listed in `translatableFields()`, replaces the locale
map with `$data[$locale] ?? $data[$fallback] ?? null`. It recurses through
plain arrays and lists (repeater rows), so a translatable field nested
inside a repeater — even nested repeaters — flattens correctly as long as
its dotted path (`'items.caption'`, `'items.gallery.caption'`) is in
`translatableFields()`. A field *not* declared translatable is never treated
as a locale map even if it coincidentally has locale-shaped keys (see
`BlockContentResolverTest`'s "does not discard a non-translatable field
coincidentally shaped like a locale map").

**`PageSpecAssembler::assemble()`** (`src/Frontend/Render/PageSpecAssembler.php`)
turns the resolver's `list<ResolvedBlock>` into the json-render `Spec` the
client consumes: each block becomes `elements[id] = {type: kind, props}`,
listed as a child of a synthetic root element `elements['$root']` whose
`type` is `tbtop-cms.render.root_kind` (default `'Fragment'`). A block whose
`id` collides with `'$root'` or a sibling's `id` is dropped (logged as
`block.reserved_or_duplicate_id`) rather than corrupting the spec.

## Form DSL for blocks

`form(S $s): array` uses tbtop/admin's field builder — full field reference
(every field type, validation, options) lives in
`vendor/tbtop/admin/docs/ai/fields.md` in this host; do not re-derive it
here. Block-specific usage:

- **`->translatable()`** — marks a field as locale-varying; drives both the
  editor's per-locale tabs and `Block::translatableFields()`'s default
  discovery (see [`Block` API](#block-api) above). Works inside
  `->repeater()->fields([...])` at any nesting depth.
- **Repeaters** (`$s->repeater('items')->fields([...])`) — rows arrive at
  `resolve()` as a plain list under `$input['items']`; nothing guarantees
  each row is well-formed (an admin can save a partial row, or `$input`
  itself is untrusted per the table below), so `resolve()` must still guard
  each row (`is_array($row)`, `$row['field'] ?? null`) rather than assuming
  the form's shape. See any real block's `resolve()` for the pattern
  (`ProjectsGridBlock::items()` in the divotek host).
- **Media fields** (`$s->media('image')`) — store the media *id*
  (string/int), never a URL. Resolve it in `resolve()` via
  `$ctx->mediaUrl($input['image'] ?? null)`, which returns `null` for a
  missing/pathless row — always guard the field type before calling it
  (`is_string($value) || is_int($value)`), since editor input is untrusted.
- **Richtext fields** (`$s->richtext('body')`) — store the editor's document
  shape; never pass it straight through to props. Resolve via
  `$ctx->richtextHtml($input['body'] ?? null)` to get the HTML string the
  React side expects.
- **Page pickers** — no dedicated field type exists in this package. Both
  real hosts surveyed link to pages with a plain `$s->text('href')` (the
  admin types or pastes the path) rather than a page-relationship field;
  treat this as the current norm, not a gap to work around silently.

**Editor caveat** (`src/Admin/Fields/BlockContent.php` docblock): the admin block
editor unions every registered block's form fields into one flat repeater, so two
kinds declaring the same field name with different types collide in the editor.
Name fields distinctly across kinds, or keep the shared name's field type identical.

## Form-handling blocks

A block that collects an anonymous submission implements
`HandlesFormSubmission` (`src/Block/HandlesFormSubmission.php`) in addition
to extending `Block`:

- **`submitRules(array $blockData, SubmitContext $ctx): array`** — Laravel
  validation rules for the POSTed payload, keyed by field name. `$blockData`
  is the block's raw (already locale-flattened) stored data, so rules can
  depend on admin-configured copy (e.g. a required field toggled per
  instance).
- **`handleSubmit(array $payload, array $blockData, SubmitContext $ctx): void`**
  — runs only after `submitRules()` passes. This is the block's own delivery
  contract: forward to a CRM, queue a job, store a lead — whatever the block
  itself needs to do with a validated submission. It is unrelated to and
  runs independently of the cms's own admin-notification fan-out (mail/bell/
  Telegram) — see [./notifications.md](./notifications.md#submission-lifecycle)
  for that pipeline. A thrown exception here fails the request and marks the
  stored `FormSubmission` `Failed` (see `FormSubmitController::__invoke()`);
  it does not stop the notification fan-out, which is dispatched separately
  via the `SubmissionReceived` event before `handleSubmit()` runs.

`SubmitContext` (`src/Block/SubmitContext.php`) is the trimmed version of
`ResolveContext` available at submit time: `locale`, `fallbackLocale`,
`page` — no `source` or media/richtext helpers, since submission validation
doesn't render anything.

**The route**: `tbtop-cms.form.submit`, `{locale}/{page}/{block}`
(`Tbtop\Cms\Frontend\Http\Controllers\FormSubmitController`), throttled by
`tbtop-cms.forms.throttle` (default `'10,1'`, `src/Cms.php`). A form block's
props never hardcode this URL — `FormActionBinder`
(`src/Frontend/Form/FormActionBinder.php`) injects it as `props.action` for
every resolved block whose registered `Block` instance implements
`HandlesFormSubmission`, since json-render components only ever see their
own `props`, never their element id.

**Honeypot**: `tbtop-cms.forms.honeypot_field` (default `'website'`) names a
field the frontend must render hidden-but-focusable (not `display:none` or
`type=hidden` — those get skipped by some bots; use off-screen positioning +
`tabindex="-1"` + `autocomplete="off"`, see `ContactForm.tsx` for the
pattern). `FormActionBinder` exposes the field name as `props.honeypotField`
only when configured; `FormSubmitController::trippedHoneypot()` silently
returns the same success response (no validation errors, nothing persisted
or delivered) when that field arrives non-empty — never signal a honeypot
hit back to the caller. Set the config value to `null`/`false` to disable
the check and stop exposing the prop entirely.

**`ContactForm`** (`src/Block/Blocks/ContactForm.php` /
`resources/js/cms/blocks/ContactForm.tsx`) is the reference form block:
fixed `name`/`email`/`message` fields (not admin-configurable — only the
heading/submit-label/success-message copy is), validated by `submitRules()`
(`required|string|max:200` etc.), and a no-op `handleSubmit()` — see the
class docblock: notifying the team is the cms's own concern
(`SubmissionReceived` → `SubmissionNotifier`), not this block's. A host that
wants delivery beyond that fan-out (CRM push, lead storage) swaps
`handleSubmit()`, either by extending `ContactForm` or writing its own
`HandlesFormSubmission` block; the notification fan-out keeps running either
way since it doesn't depend on `handleSubmit()`.

## Client side

`resources/js/cms/catalog.ts` and `resources/js/cms/registry.tsx`
(`defineCatalog`/`defineRegistry` from `@json-render/core`/`@json-render/react`)
are the client's half of the three-layer contract:

- **`catalog.ts`** declares, per `kind()`, a zod props schema — this is what
  `Renderer` validates each element's `props` against before render.
  Includes the synthetic `Fragment` root kind
  (`tbtop-cms.render.root_kind`, default `'Fragment'`): an empty-props
  passthrough that renders its children in order, standing in for the page
  itself in the spec.
- **`registry.tsx`** maps the same keys to React components
  (`defineRegistry(catalog, {components: {...}})`).
- **Unknown kind**: if a spec element's `type` has no entry in the registry
  (and no `fallback` prop was passed to `<Renderer>` — `CmsPage.tsx` in this
  package and both real hosts surveyed pass none), `@json-render/react`
  logs `console.warn('No renderer for component type: ...')` and renders
  nothing for that element. A block missing its client-side wiring doesn't
  crash the page — it silently vanishes. Check the browser console when a
  block "isn't showing up."
- **Extending vs. rewriting the catalog**: the package ships exactly two
  built-in blocks (`Hero`, `ContactForm`) with their own `catalog.ts`/
  `registry.tsx` as a working example, importable via the `@cms` alias. In
  practice, every real host surveyed (divotek_com_ua, autofocus) does not
  import or spread the package's `catalog`/`registry` — each writes its own
  `resources/js/cms/catalog.ts` and `registry.tsx` from scratch, reimplementing
  even `Hero`/`ContactForm`/`Fragment` to match its own visual language and
  prop shapes. Treat "write your own" as the norm for any host with more
  than a couple of blocks; reusing the package's catalog only suits a host
  staying with the stock `Hero`/`ContactForm` blocks unmodified
  (`cms-starter-kit`-style bare wiring — see wiring.md).
- **Host-only client interactivity**: state that spans multiple blocks on a
  page (e.g. divotek's `ContactModalContext` — a CTA in one block opens a
  contact form rendered by a different block) is not a package concern.
  Provide it as a React context around `<Renderer>` in the host's own
  `CmsPage.tsx`, with a no-op fallback value so a page without the relevant
  block still renders (see `ContactModalContext.tsx`'s `NOOP` value in
  divotek_com_ua). The package's `ResolveContext`/props contract has no
  concept of cross-block client state — it's resolved entirely client-side.

## Server-side / Blade rendering

The three-layer rule above covers blocks rendered through the json-render
React pipeline. A host that renders a block server-side instead (plain
Blade) owns the whole path itself: the package resolves props via
`BlockContentResolver` but ships **no Blade view convention, no view
registration, and no automatic blade fallback** — a block without a
catalog/registry entry does not fall back to a Blade template. To render
server-side, resolve the envelope yourself (or read `ResolvedBlock`s the
package assembled) and render your own `resources/views/...` template keyed
however your host likes. Treat this as a host-side escape hatch, not a
supported alternative to the catalog/registry contract.

## Layout blocks

Blocks placed in the header/footer slots are a different delivery path from
a page's own content: they're resolved by `LayoutResolver` and shared as the
Inertia prop `cms.layout.{slot}` (via `ShareCmsProps` middleware, cached
under the `layout` cache key), not assembled into the page's `render` spec.
The catalog/registry contract is identical either way — a header block still
needs a matching `kind()`/catalog entry/registry entry — but a host renders
`cms.layout.header`/`cms.layout.footer` itself, typically from a `SiteChrome`
component wrapping `<Renderer spec={render} .../>`. See
[./wiring.md](./wiring.md) for how `SiteChrome` is wired into `CmsPage.tsx`.

## Where does my code go

| Concern | Goes in |
|---|---|
| Coerce/guard untrusted editor `$input` into typed props | `Block::resolve()` (PHP) |
| Validate an anonymous form submission | `HandlesFormSubmission::submitRules()` |
| Deliver a validated submission somewhere (CRM, lead table, job) | `HandlesFormSubmission::handleSubmit()` |
| Editor-facing field list, labels, translatability | `Block::form()` |
| Visual presentation of resolved props | the React component (`registry.tsx`) |
| Block-derived SEO / JSON-LD | see seo.md / recipes.md, not here |
| Cross-block client state (modals, shared UI) | host's `CmsPage.tsx`, a React context — see [Client side](#client-side) |

## Reference implementations

- Package: `HeroBlock` (`src/Block/Blocks/HeroBlock.php`) + `Hero.tsx`
  (`resources/js/cms/blocks/Hero.tsx`) — the minimal PHP/catalog/registry
  triple; `ContactForm` (`src/Block/Blocks/ContactForm.php`) + `ContactForm.tsx`
  — the minimal form-handling block, honeypot included.
- Host examples: `divotek_com_ua/app/Cms/Blocks/*` (26 blocks) — notably
  `Concerns/NormalisesInput.php`, a **host-authored** trait (`str()`,
  `text()`, `rows()`, `link()`, `bool()`, `cta()`, `divider()`) that every
  block in that host uses to coerce loose admin input into typed props. It
  is a host pattern for writing `resolve()` bodies, not part of the package
  API — copy the idea, not a package import.

## Tests to read for behaviour

- `tests/Block/BlockContentResolverTest.php` — sort/legacy-order, inactive
  skip, unknown-kind skip vs. strict-throw, per-block failure isolation,
  locale flattening including the "coincidentally locale-shaped" edge case,
  the `source` argument reaching `ResolveContext`.
- `tests/Block/BlockRegistryTest.php` — config vs. `extend()` registration,
  duplicate/empty-kind/non-`Block` rejection, extension boot laziness and
  ordering, `flushExtensions()` test isolation.
- `tests/Block/TranslatableFieldsTest.php` — default `translatableFields()`
  discovery at arbitrary repeater nesting, and the top-level/nested
  same-name-field collision case.
- `tests/Block/LocaleFlattenerTest.php`, `tests/Block/ResolveContextTest.php`,
  `tests/Block/HeroBlockTest.php`, `tests/Block/ContactFormTest.php` — flattener
  edge cases, `ResolveContext` helper behaviour, and the two built-in blocks'
  own `resolve()` contracts end to end.
- `tests/Form/SubmissionNotifierTest.php`, `tests/Form/SubmissionRetrierTest.php`
  — the notification/retry side of a submission; read them from
  notifications.md's perspective, not this file's.
