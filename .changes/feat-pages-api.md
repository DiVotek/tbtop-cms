# feat(api): a token-authenticated pages API for articles under given categories

touches: new routes under /api/cms/v1 (off by default), config, artisan commands, cms:doctor, auth guards, composer suggest

> ⚠️ **Contract** New routes `tbtop-cms.api.*` under `api/cms/v1` (pages index/show/store/update/destroy/publish, `types/{type}/fields`, `locales`, `media`), registered only when `tbtop-cms.api.enabled` is true and `laravel/sanctum` is installed. Request and response shapes in docs/host/ai/api.md.
> ⚠️ **Contract** New config key `tbtop-cms.api.enabled` (`env('TBTOP_CMS_API', false)`).
> ⚠️ **Contract** New command `cms:api-token {user} --parents= [--name=]`.
> ⚠️ **Contract** New auth guard `cms-api` (driver `sanctum`, the admin guard's provider), added only when the host has none by that name.
> ⚠️ **Contract** Token scope format in Sanctum abilities: `pages:read`, `pages:write`, `pages:parent:<id>`, `pages:type:<type>`. A token issued another way reaches only what these name; `*` reaches nothing.
> ⚠️ **Contract** `laravel/sanctum` is a `suggest` and a dev dependency; new `cms:doctor` check `pages_api`.

A service holding a token can list, create, edit, publish and delete draft
articles under the categories the token names, and upload their media. Every
write is a model save as the admin does, so slugs, revisions, refs, audit and
the cache bump follow; PATCH writes only the keys it is sent, unlike the
admin's whole-form save. `/types/{type}/fields` and the request rules come
from one mapping of the type's DSL fields, so the description never offers a
field the validation refuses.

Needs tbtop/admin with `Tbtop\Admin\Media\MediaIngest` (DiVotek/tbtop#390):
the constraint moves from `^0.7.1` to that release with this change.

Tests: `tests/Api/PagesApiTest` (4; each mutated by hand and caught — PATCH
keeps unsent SEO and texts, a token without `pages:write` is refused, a token
reaches only its category (POST elsewhere, GET of a system page), publish with
a blank title in a frontend-active locale is 422 and stays draft).

Read by eye: `cms:api-token` with an unknown id names it; `cms:doctor` with
the API on and Sanctum absent shows the `pages_api` warning.
