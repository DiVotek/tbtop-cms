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
4. `php artisan cms:api-token social@example.com --parents=12,15` — the user
   must be a CMS admin; the token is printed once.

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

Anything else — another category, the home page, a system page, a grandchild
— is 403, by id as in lists. A `*` token reaches nothing: the parents are
never implied. A category is read-only; only its children are written.

## Endpoints

All bodies are JSON; errors are Laravel's `{message, errors?}`.

| Method, path | Ability | Answer |
|---|---|---|
| `GET /pages?parent_id=&type=&status=&per_page=&page=` | read | `{data: [Page], meta: {current_page, last_page, per_page, total}}`, `per_page` 1–100 (50) |
| `GET /pages/{id}` | read | `Page`; 404 missing or trashed, 403 out of reach |
| `POST /pages` | write | 201 `Page`, always a draft. `parent_id` required; type and subtype follow from the parent as in the admin's create form (`PageLeafType`) |
| `PATCH /pages/{id}` | write | `Page`. Only the keys sent are written; a translatable value keeps the locales not sent. Status and schedule are not patchable |
| `DELETE /pages/{id}` | write | 204, soft delete; 404 missing, 409 published |
| `POST /pages/{id}/publish {published_at?}` | write | `Page`; 404 trashed, 409 already published, 422 `translations.{locale}.title` when a frontend-active locale has no title (no fallback). `published_at` defaults to now; a future one schedules |
| `GET /types/{type}/fields` | read | `[Field]` for a base type or subtype key; 404 unknown, 403 when its base type is not writable for the token |
| `GET /locales` | read | `{default, active: [locale]}` — `active` is the frontend-active locales publish checks |
| `POST /media` | write | 201 `{id, url}`. Exactly one of `url` (http/https, downloaded through the admin's SSRF guard) or multipart `file` (capped by `MediaUploadLimit`), plus `name?`, `alt?`; a file over the limit is a validation error, a refused URL or file type is 422 with the media library's own message |

**Write body** (`POST` and `PATCH`; on `PATCH` every key is optional):

```json
{
  "parent_id": 12,
  "translations": {"en": {"title": "…", "name": "…", "slug": "…", "heading": "…", "summary": "…", "body": "<p>…</p>",
                          "seo": {"title": "…", "description": "…", "focus_keyword": "…", "og_title": "…", "og_description": "…", "canonical": "https://…"}}},
  "image": 41, "og_image": 41,
  "fields": {"cardLabel": {"en": "…"}, "steps": ["…"], "poster": 41},
  "content": [{"kind": "Hero", "data": {…}, "active": true}]
}
```

`translations` keys are the content locales; `title` in the default locale is
required on create, and a missing `name` takes the title. `body` and any
`richtext` field take HTML limited to `p, h2–h4, ul, ol, li, strong, em, a,
blockquote` and are stored as richtext nodes. Media are ids from `POST
/media`. An unknown key inside `translations`, `seo` or `fields` is 422, not dropped; an unknown top-level key is ignored.

**Page** adds `id, parent_id, type, subtype, child_type` (the type a page
created under a category gets), `status, display_state, published_at,
unpublish_at, is_indexable, url{locale}` (absolute, null without a slug),
`preview_url{locale}` (signed, null when live or without
`Cms::frontendRoutes()`), `created_at, updated_at`. Every content locale is
present in `translations`, blanks as `null`.

**Field**: `{key, label, kind, required, translatable, multiple?, options?, children?}`.
`kind` is one of `text, textarea, richtext, tags, select, media, ref,
repeater, blocks`. Common fields come first (`title`, `seo.description`,
`image`, `content`…), then the type's own as `fields.<name>`. Labels are in
the site's default language.

## Why the field list matches the validation

`Api/Fields/PageFields` maps the type's DSL fields (`PageTypeInterface::fields()`,
base then subtype, as the edit form folds them) once. `/types/{type}/fields`
describes that mapping, and the request rules are the type's own
(`RuleWalker::collect()`, the rules the admin form enforces) plus a shape rule
per kind built from the same mapping. A DSL kind the API does not support
(boolean, number, date, link, relation, …) is left out of both, so a client
cannot be told about a field it may not send; a repeater with such a child is
left out whole. No tbtop/admin field JSON leaves the API.
