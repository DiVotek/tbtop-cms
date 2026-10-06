# fix(blocks): review batch — discovery, doctor checks, error blocks, page text fallback

touches: block registry and discovery, cms:doctor, cms:blocks:types, form resolver, shared block usage, page props and render spec, CmsRenderer, page SEO/breadcrumbs/JSON-LD text, site settings contacts, docs

> ⚠️ **Contract** `render.errors` and `page.fields` are always JSON objects — `{}` when empty, never `[]`, and never a list for block ids `"0"`, `"1"`….
> ⚠️ **Contract** A block that failed to resolve (resolve() threw, unregistered kind, missing shared block) reaches the client as `{type: 'CmsBlockError', props: {kind, message}}` in its own place, not as its kind with `{}` props. `render.errors[id]` still carries the message; `CmsRenderer` behaves as before.
> ⚠️ **Contract** `page.name`/`title`/`heading`/`summary` (page and error-page props), breadcrumb labels, meta title/description and JSON-LD names fall back blank-aware: this locale, then the default language, then `null` (was: `''` for a blank translation, `app.fallback_locale` for a missing one). The four props are `string|null`.

Closes the bugs found reviewing the shared-block/discovery batch:

- A block class listed in `blocks.types` and found by discovery threw
  "already registered" on the lazy registry boot — a 500 on every page. The
  discovered copy of an already registered class is skipped; two different
  classes with one kind still throw, naming both.
- A discover entry with a wrong namespace, a missing folder or a list shape
  registered nothing silently (a list even threw a TypeError under strict
  types). Malformed entries are skipped by the registry; `cms:doctor`
  (`block_discovery`) and `cms:blocks:types` warn about both cases.
- New doctor check `broken_shared_references`: per page (trashed included)
  and layout slot, how many rows point at a missing or other-kind shared block.
- A shared block placed only on a trashed page could be deleted. Usage index
  and counts read trashed pages; the usage table marks them "In trash", and
  detach works on them. **Old page revisions are out of scope**: restoring a
  revision that points at a since-deleted shared block still shows the
  placeholder.
- `FormResolver` dropped array values to null: `togglebuttons`/`upload`
  `->multiple()` are now `list<string>`, `daterange` is
  `{from: string|null, to: string|null}`. A non-repeater field with sub-fields
  passes its value through as `unknown`, and `cms:blocks:types` warns.
- Site settings contacts: row fields were `required()`, so the promised
  "empty rows are dropped" never ran — validation rejected the empty row
  first. Phone/email rows are optional per field; a social row needs label
  and link once any of its fields is filled (`required_with`).
- Docs: `render.errors`, `cms.debug` (= `app.debug`; there is no
  `tbtop-cms.*` key), the error element shape and discovery rules; an
  UPGRADING section for #238's form-driven default `resolve()`.

Tests: `tests/Block/BlockDiscoveryTest.php` (types + discovery, two classes
one kind), `tests/Health/BlockDiscoveryCheckTest.php`,
`tests/Health/BrokenSharedReferencesCheckTest.php`,
`tests/SharedBlock/SharedBlockAdminTest.php` (trashed page placement),
`tests/Block/FormResolverTest.php` + `tests/BlockTypes/BlocksTypesCommandTest.php`
(array-valued kinds), `tests/Frontend/BlockPropsParityTest.php` +
`resources/js/cms/blockProps.parity.test.ts` (resolve() output of the
package's blocks against the generated zod; rerun the PHP test with
`UPDATE_BLOCK_FIXTURES=1` to refresh the fixture), `tests/Frontend/PageRenderTest.php`
(`{}` contract, `CmsBlockError` element, no `app.debug` dependency),
`resources/js/cms/CmsRenderer.test.tsx` (server error element),
`tests/Admin/SiteContactsSaveTest.php`, `tests/Frontend/PageSeoTest.php`
(blank-aware fallback).

**Read by eye:**
- `src/Block/BlockRegistry.php`, `src/Block/BlockDiscovery.php`
- `src/Frontend/Render/PageSpecAssembler.php`, `resources/js/cms/blockErrors.ts`
- `src/Page/Support/PageTranslation.php` and its new callers (`PageController`, `ErrorResponse`, `Breadcrumbs`, `PageOwnSeo`, `SchemaContextFactory`, `CollectionPage`)
- `src/Admin/Pages/SiteSettingsPage.php`
- `src/Block/Support/FormResolver.php`

**Assumptions:**
- Layout slots (`cms.layout`) still ship `ResolvedBlock` arrays with `error`;
  a host building a spec from them keeps passing `error` as `spec.errors[id]`,
  which `CmsRenderer` still honours.
- `required_with:socials.*.x` resolves `*` to the same row (Laravel's
  dependent-rule wildcard replacement); the admin client does not enforce it,
  the server does.
- The SEO report/checklist reuse `PageOwnSeo`, so a page blank in one locale
  now counts the default-language title/summary as present there — what the
  page actually renders.
