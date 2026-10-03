# feat(blocks): form-driven resolve() and schema for blocks without their own

touches: block resolve, block types command, form resolver

> ⚠️ **Contract change** `Block::resolve()` default — was identity (`return $input`), now resolves every form field by its kind (`Block\Support\FormResolver`): media id → URL, link value → `{href, type, label}`, richtext → HTML, number → float, repeater → resolved rows, blank/malformed → null. A host block that relied on raw input passing through gets resolved props instead; input with no form field is dropped. Blocks with their own `resolve()` are untouched.
> ⚠️ **Contract change** `cms:blocks:types` — a block that keeps the base `resolve()` now gets a schema derived from its form instead of the `z.record(z.string(), z.unknown())` fallback; a host whose components typed such props as `unknown` sees them typed.

A simple block is now a PHP class with `kind()` and `form()` plus a React
component: props and their zod schema both come from the form's field kinds,
through one rule table, so they cannot drift apart. `resolve()` stays the
escape hatch for a shape the form cannot express.
