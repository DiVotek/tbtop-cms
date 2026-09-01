# Merchant notification templates are Blade, editable by owners only

The registry's built-in notification templates are already Blade views, and `TemplateRenderer` renders a merchant override through the exact same renderer as the built-in path — the frozen `TemplateStore` contract only ever returns a string that becomes template source. Given that, a merchant override is stored as Blade source too (a subject section plus a body), composed with the built-in path's own renderer rather than a second rendering engine. Because Blade executes arbitrary PHP, editing an override is restricted to owner admins (`is_owner`) — every other admin only sees the channel toggles.

## Considered Options

- Placeholder syntax (`{key}`) — safe, no loops or conditions, but would have forced every notification payload (storefront order lines, form fields) to pre-render lists and conditionals into flat strings before they ever reach the template.
- Sandboxed Blade (token-level ban of `@php`, `{!! !!}`, raw PHP tags) — real ongoing work to keep in sync with Blade's own directive set, and breaks silently on a Blade/Laravel version bump; a token blocklist is false safety against a determined owner anyway.
