# feat(pages): TS/zod types for the page prop, per page type and subtype

touches: cms:blocks:types, page.fields, config pages.types_path

> ⚠️ **Contract** `cms:blocks:types` writes a second file, `pages.generated.ts`, beside `blocks.generated.ts` (new config key `pages.types_path`; `false` turns it off), and `--check` covers it: a host's CI check fails until the command is re-run once after upgrading.

The generator now types the `page` prop: one zod object per registered type
and subtype, discriminated by the literal `type`/`subtype` pair, their union
`cmsPage`/`CmsPage`, and `pageProps`/`PageOf<K>` keyed by type or subtype
key. `page.fields` follows the resolver: base part, subtype part over it,
then `backReferences()` lists. A part keeping the base `resolveFields()` is
typed from its form; an override from its `@return array{...}`, or — without
one — a warning and a `z.looseObject`. The alias ordering and file header the
blocks emitter used moved into `ZodModule`, shared by both emitters
(behaviour-preserving: `blocks.generated.ts` is byte-identical).

Tests: `PagesTypesCommandTest` (variants, back-reference over a base field,
untyped warning, orphan subtype skipped, `--check` on a stale pages file,
configured path and `false`), `PagePropsParityTest` + `pageProps.parity.test.ts`
(real page props of every fixture type, full and empty, against the
generated schema; the controller's `page` keys against the generator's).

Read by eye: `resources/js/cms/testing/pages.generated.ts`.
