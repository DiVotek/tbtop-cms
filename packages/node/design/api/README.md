# Public API sketches (design, not for merge)

Five shapes of the host-facing API of `@tbtop/cms` (Node), from canonical to
minimal. Each one builds the same reference site (`shared/reference.ts`: Hero
plus a host Promo block, the built-in `page` type extended, a `service` type
with its own `Service` JSON-LD, `seo.extend`, a Site settings tab, Better
Auth built from `db`, a Cloudflare Worker), and each one runs through the same
checks (`shared/prove.ts`) twice: on Node/Bun with SQLite, and as a Worker
module on a D1-like binding, KV and R2.

| Dir | Variant | Package layer |
|---|---|---|
| `v1-compose/` | Explicit factories, as #333 plus Q15 sugar | `package.ts` re-exports `kit/` |
| `v2-config/` | One `defineConfig` object (Payload style) | `define.ts` (client-safe), `package.ts` |
| `v3-plugins/` | Everything is a plugin (Better Auth / Vite style) | `define.ts`, `package.ts` |
| `v4-builder/` | Fluent typed builder (Elysia / tRPC style) | `define.ts`, `package.ts` (over V2) |
| `v5-zero/` | Records of fields, `serve(site, { db })` / `cloudflare(site)` | `fields.ts`, `define.ts`, `package.ts` (over V4) |

`kit/` is what every variant needs from the package and #333 does not have yet:
`defaultAuth` (Better Auth from `db`: dialect, `tbtop_*` models, admin
plugin, `ensureUser`), `createCmsAdmin`, `cmsRoutes`, `migrateAll`, a Kysely
D1 dialect, `runtimeFromEnv` and `workerEntry` (Q16).

```bash
cd packages/node
bun test ./design/api                    # 45 checks: 5 variants x (Node + Workers)
bunx tsc --noEmit -p design/api          # typecheck against src/
design/api/scripts/negative-types.sh     # a wrong page.fields type must fail tsc in every variant
```

`shared/fakeD1.ts` is a D1 binding over bun:sqlite (no transactions outside an
atomic `batch`, the
pragma join refused); workerd itself is not run here.
