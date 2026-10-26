# Pages API

> Part of the host-builder guide. Start at [./README.md](./README.md).

A token-authenticated JSON API under `/api/cms/v1` that lets another service
(tbtop-social, an importer) write articles into the categories it was given.
It is off by default and writes through the same model saves as the admin:
slugs, revisions, page refs, the audit entry and the cache bump happen as
they do for an editor.

## Turning it on

1. `composer require laravel/sanctum`, publish and run its migration
   (`php artisan vendor:publish --tag=sanctum-migrations && php artisan migrate`).
2. Add `Laravel\Sanctum\HasApiTokens` to the user model (`tbtop-cms.admin.model`).
3. `TBTOP_CMS_API=true` (`tbtop-cms.api.enabled`).
4. Create a dedicated user for the service and never log in as it:
   `php artisan cms:admin:create social@example.com --name="tbtop-social"`
   prints a generated random password — discard it.
5. `php artisan cms:api-token social@example.com --parents=12,15` — the
   argument is the user's email, the user must be a CMS admin; the token is
   printed once.

**The token's user is a full CMS admin.** The token limits what the API
lets it reach, but the account itself could sign in to the panel with every
admin right, which is why it gets a password nobody keeps. Revoking its admin
access (`cms:admin:revoke`) silences its tokens too.

**Revoking a token**: `php artisan tinker --execute="App\Models\User::firstWhere('email', 'social@example.com')->tokens()->delete();"`
(`App\Models\User` being `tbtop-cms.admin.model`; this drops all of that user's tokens, add `->where('name', 'cms-api')` before `delete()` for one name). The next request with a revoked token is 401.

Without Sanctum the routes are not registered at all; `cms:doctor`
(`pages_api`) and `cms:api-token` say why. The package registers the guard
`cms-api` (driver `sanctum`, the provider of `tbtop-cms.admin.guard`) unless the
host defines one by that name. Each request runs as the token's user on the
panel guard, so authorship, revisions and the audit log name that user; with
`tbtop-cms.roles` on, the user also needs `tbtop-cms.pages`.

## What a token reaches

A token's reach lives in its Sanctum abilities, so revoking the token revokes
it and no table is added:

| Ability | Grants |
|---|---|
| `pages:read` | the GET endpoints |
| `pages:write` | POST, PATCH, DELETE, publish, media |
| `pages:parent:<id>` | the category `<id>` (read) and its direct children (read and write) |
| `pages:type:<type>` | which child base types are writable under those parents; `cms:api-token` grants `article` |

No token reaches a system page (home, error pages), and `POST /media` needs a
token that names at least one parent.

Anything else — another category, the home page, a system page, a grandchild
— is 403, by id as in lists. A `*` token reaches nothing: the parents are
never implied. A category is read-only; only its children are written.

## Endpoints

All bodies are JSON; errors are Laravel's `{message, errors?}`.

| Method, path | Ability | Answer |
|---|---|---|
| `GET /pages?parent_id=&type=&status=&per_page=&page=` | read | `{data: [Page], meta: {current_page, last_page, per_page, total}}`, `per_page` 1–100 (50) |
| `GET /pages/{id}` | read | `Page`; 404 missing or trashed, 403 out of reach |
| `POST /pages` | write | 201 `Page`, always an `article` draft with the type's starter blocks. Its subtype follows from the parent as in the admin's create form (`PageLeafType`); the body has no `type`. A missing or invalid `parent_id`, or a parent that takes no article, is 422 on `parent_id`; a valid parent outside the token's reach is 403 |
| `PATCH /pages/{id}` | write | `Page`. Only the keys sent are written; a translatable value keeps the locales not sent. A title sent for the default or a frontend-active locale may not be null or blank. `status`, `parent_id`, `is_indexable` and the schedule are not patchable (422) |
| `DELETE /pages/{id}` | write | 204, soft delete; 404 missing, 409 published |
| `POST /pages/{id}/publish {published_at?}` | write | `Page`; 404 trashed, 409 when the status is already Published — also when its `unpublish_at` has passed and the page is off the site, which is unpublished in the admin first — 422 `translations.{locale}.title` when a frontend-active locale has no title (no fallback). Without `published_at` a page keeps an earlier publish's date, else is stamped now; a future one schedules |
| `GET /types/{type}/fields` | read | `[Field]` for a base type or subtype key; 404 unknown, 403 when its base type is not writable for the token |
| `GET /locales` | read | `{default, active: [locale]}` — `active` is the frontend-active locales publish checks |
| `POST /media` | write | 201 `{id, url}`; 403 for a token with no parent. Exactly one of `url` (http/https, downloaded through the admin's SSRF guard) or multipart `file` (capped by `MediaUploadLimit`), plus `name?`, `alt?`; a file over the limit is a validation error, a refused URL or file type is 422 with the media library's own message |

**Write body** — `POST` takes exactly `parent_id, translations, image, og_image, fields`; `PATCH` the same without `parent_id`, every key optional. Any other top-level key is 422. Blocks (`content`) are not written or returned in v1.


```json
{
  "parent_id": 12,
  "translations": {"en": {"title": "…", "name": "…", "slug": "…", "heading": "…", "summary": "…", "body": "<p>…</p>",
                          "seo": {"title": "…", "description": "…", "focus_keyword": "…", "og_title": "…", "og_description": "…", "canonical": "https://…"}}},
  "image": 41, "og_image": 41,
  "fields": {"cardLabel": {"en": "…"}, "steps": ["…"], "poster": 41}
}
```

`translations` keys are the content locales; `title` in the default locale is
required on create, and a missing `name` takes the title. `body` and any
`richtext` field take HTML limited to `p, h2–h4, ul, ol, li, strong, em, a,
blockquote`, with no attribute but a link's `href`, and are stored as
richtext nodes. A link must be http, https, mailto, tel or a path on the site
(not `//host`); any other markup, attribute, link or a head element (`<title>`,
`<style>`…) is 422, never dropped. Media are ids from `POST /media`. An
unknown key inside `translations`, `seo` or `fields` is 422 too.

**Page** adds `id, parent_id, type, subtype, child_type` (the type a page
created under a category gets), `status, display_state, published_at,
unpublish_at, is_indexable`, `url` and `preview_url` — both maps keyed by
locale: `url.{locale}` absolute, null without a slug in that locale;
`preview_url.{locale}` signed, null when live, without a slug or without
`Cms::frontendRoutes()` — and `created_at, updated_at`. Every content locale is
present in `translations`, blanks as `null`.

**Field**: `{key, label, kind, required, translatable, multiple?, options?, children?}`.
`kind` is one of `text, textarea, richtext, tags, select, media, ref,
repeater` (`blocks` is reserved for when blocks are written). `multiple` is
present, as `true`, only on a select, media or ref field that holds a list.
`options` (`[{value, label}]`) only on a select, `children` only on a
repeater. Common fields come first (`title`, `seo.description`, `image`…),
then the type's own as `fields.<name>`. Labels are in the admin panel's
default locale, the key when a field has none.

## Why the field list matches the validation

`Api/Fields/PageFields` maps the type's DSL fields (`PageTypeInterface::fields()`,
base then subtype, as the edit form folds them) once. `/types/{type}/fields`
describes that mapping, and the request rules are the type's own
(`RuleWalker::collect()`, the rules the admin form enforces) plus a shape rule
per kind built from the same mapping. A DSL kind the API does not support
(boolean, number, date, link, relation, …) is left out of both, so a client
cannot be told about a field it may not send; a repeater with such a child is
left out whole. No tbtop/admin field JSON leaves the API.
