# feat(blocks): generated catalog and component registry, block discovery

touches: cms:blocks:types, block registry, config blocks.*, block docs

> ⚠️ **Contract** `cms:blocks:types` also writes `registry.generated.ts` (`blockCatalog` and `blockComponents`) when the components folder exists (`blocks.components_dir`, default `./blocks`, relative to the registry file). It then fails, writing nothing, when a registered block placeable somewhere has no `<StudlyKind>.tsx` exporting `<StudlyKind>`, or two kinds map to one component name. `--check` verifies both files. A host that keeps the folder but its own catalog and registry sets `blocks.registry_path => false`; without the folder only the types file is written, as before.
> ⚠️ **Contract** New config keys `blocks.discover`, `blocks.registry_path`, `blocks.components_dir`. A published config does not get them (`mergeConfigFrom` is shallow); the code falls back to the defaults when they are missing.

`blocks.discover` maps a namespace to a folder
(`['App\\Cms\\Blocks' => app_path('Cms/Blocks')]`). Every `.php` file under
it whose PSR-4 class is a concrete `Block` subclass is registered, in
relative-path order after `blocks.types`; traits, abstract classes and
anything else are skipped, and a missing folder contributes nothing.

The generated registry lets a host spread `blockCatalog` and
`blockComponents` into its own `defineCatalog()` / `defineRegistry()` calls
instead of listing every block twice.

Tests: `tests/Block/BlockDiscoveryTest.php` (path order, traits and abstract
classes skipped, missing folder),
`tests/BlockTypes/BlocksTypesCommandRegistryTest.php` (registry output, the
missing-component failure, `--check`, `registry_path => false`).

**Read by eye:**
- `src/BlockTypes/RegistryEmitter.php`
- `src/Console/BlocksTypesCommand.php` (path handling between the types and registry files)
- `src/Block/BlockDiscovery.php`
- `src/Block/BlockRegistry.php`

**Assumptions:**
- A block whose `placements()` is `[]` is never drawn and needs no component.
- The component folder's existence is the opt-in; a host without it is unaffected.
