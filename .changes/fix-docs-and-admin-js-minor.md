# fix: host-guide corrections and three small admin-JS fixes

touches: docs/host/ai (README, blocks, inventory, seo, wiring); admin link field; SERP preview

Docs now match `src/`: legacy SEO keys with no field, generated block schemas
and registry, `console.error` only in preview/debug, `TranslatablePaths::of()`,
nine commands, and the full page-prop list. The link field's page picker
carries the field's `aria-describedby`, a padded anchor is trimmed before the
client check (as the server does), and the SERP title meter counts the last
wrapped line instead of the widest one.
