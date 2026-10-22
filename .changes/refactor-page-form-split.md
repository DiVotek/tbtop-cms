# refactor: split PageForm into fields, SEO tab, guards and writer

touches: nothing visible; the page create/edit forms and saving behave the same

`PageForm` (988 lines) stays the entry point (`fields`, `seoFields`, `save`,
`loadRow`, …) and delegates: `PageSeoFields` builds the SEO tab,
`PageFormWriter` runs `save()` in steps (resolve type and parent, guard,
fill, persist), `PageFormGuards` holds the pre-save checks,
`PageFormAttributes` maps the form onto model attributes and
`PageFormValues` coerces raw values.

Last of five splits from arti `spec-cms-tbtop-split-large-classes`.
