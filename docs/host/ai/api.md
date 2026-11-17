# Pages API

> Part of the host-builder guide. Start at [./README.md](./README.md).

A token-authenticated JSON API under `/api/cms/v1` that lets another service
(a social posting service, an importer) write articles into the categories it was given.
It is off by default and writes through the same model saves as the admin:
slugs, revisions, page refs, the audit entry and the cache bump happen as
they do for an editor.

## Turning it on

1. `composer require laravel/sanctum`, publish and run its migration
   (`php artisan vendor:publish --tag=sanctum-migrations && php artisan migrate`).
2. Add `Laravel\Sanctum\HasApiTokens` to the user model (`tbtop-cms.admin.model`).
3. `TBTOP_CMS_API=true` (`tbtop-cms.api.enabled`).
4. Create a dedicated user for the service and never log in as it:
   `php artisan cms:admin:create social@example.com --name="Publisher"`
   prints a generated random password — discard it.
5. `php artisan cms:api-token social@example.com --parents=12,15` — the
   argument is the user's email, the user must be a CMS admin; the token is
   printed once.

Every route is rate limited by `tbtop-cms.api.throttle` (default `'60,1'`,
60 requests a minute per token user; `''` turns it off). Over the limit the
API answers 429 with a `Retry-After` header. The limit runs after
authentication, so a request with a missing or wrong token gets its 401
without being counted; a host that wants those limited too puts a per-IP
limit in front (the proxy, or its own middleware on the `api/cms/v1` prefix).

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
it and no table is added. It answers three questions: which pages, which
actions, which fields.

| Ability | Grants |
|---|---|
| `pages:page:<id>` | that page alone, of any type, a system page (home, error pages) included: read and write |
| `pages:parent:<id>` | the category `<id>` (read) and its direct children (read and write) |
| `pages:tree:<id>` | the page `<id>` (read) and every descendant at any depth, of any type (read), system pages excluded |
| `pages:type:<type>` | which child base types are writable (and creatable) under parents and trees; `cms:api-token` grants `article` by default |
| `pages:read` | the GET endpoints |
| `pages:create`, `pages:update`, `pages:publish`, `pages:delete` | `POST /pages`, `PATCH`, `POST …/publish`, `DELETE` |
| `media:upload` | `POST /media` |
| `pages:write` | all four write actions and `media:upload` (the pre-existing, all-or-nothing form; tokens issued with it keep working) |
| `fields:seo`, `fields:heading`, `fields:text`, `fields:url`, `fields:type`, `fields:media` | the field groups a write may touch; none of them: every field |

System pages are reached only through an explicit `pages:page`: never through
a parent or a tree. A tree or parent root stays read-only unless it is also
named in `pages:page`; below it only the types in `pages:type` are written.
`POST /media` needs a token that names at least one page, tree or parent.
Anything else is 403, by id as in lists. A `*` token reaches nothing: pages
are never implied.

Field groups, for a write (`POST` and `PATCH`): `seo` is
`translations.*.seo.*` and `og_image`; `heading` is `translations.*.heading`
(the H1); `text` is `name`, `summary`, `body`; `url` is `slug`; `type` is
`fields.*`; `media` is `image`. A body key of a known field outside the
token's groups is a 403 `{message, fields: [path…]}` before anything is
validated or written; an unknown key stays a 422. Reads are not trimmed by
group. The token's user is a full CMS admin, so the groups are the only guard
on what a key may rewrite: `pages:page:<home>` with `fields:text` rewrites the
home page's text. A `slug` sent for a system page is a 422. Publishing or
deleting a system page is a 409.

Issue one with `cms:api-token {user} --parents= --pages= --trees= --types=article --actions= --fields= --name=`:
at least one of `--parents`, `--pages`, `--trees`; `--actions` from `read,
create, update, publish, delete, media` (default all, stored as `pages:read` +
`pages:write`); `--fields` from the six groups (default all, none stored).
`create` needs the `text` group, and the `type` group when the article type
has required fields. The command prints the resulting scope, then the token
once. Without the new options it issues what it always did.

## Endpoints

All bodies are JSON; errors are Laravel's `{message, errors?}`.

| Method, path | Ability | Answer |
|---|---|---|
| `GET /token` | any token | `{pages, trees, parents, types, actions, fields}`: the calling token's reach; `actions` is expanded (`pages:write` lists create, update, publish, delete, media), `fields: []` means every field; 401 without a token |
| `GET /pages?parent_id=&type=&status=&per_page=&page=` | read | `{data: [Page], meta: {current_page, last_page, per_page, total}}`, `per_page` 1–100 (50) |
| `GET /pages/{id}` | read | `Page`; 404 missing or trashed, 403 out of reach |
| `POST /pages` | create | 201 `Page`, always an `article` draft with the type's starter blocks. Its subtype follows from the parent as in the admin's create form (`PageLeafType`); the body has no `type`. A missing or non-integer `parent_id`, or a parent of the token (a `pages:parent`, or the root or any category of a `pages:tree`) that takes no article, is 422 on `parent_id`; any other id, existing or not, is 403 |
| `PATCH /pages/{id}` | update | `Page`. Only the keys sent are written; a published page is edited in place, live on save, as an editor's save in the admin is (a `slug` change moves its URL); a translatable value keeps the locales not sent. A `name` sent for the default or a frontend-active locale may not be null or blank. `status`, `parent_id`, `is_indexable` and the schedule are not patchable (422) |
| `DELETE /pages/{id}` | delete | 204, soft delete; 404 missing, 409 published |
| `POST /pages/{id}/publish {published_at?}` | publish | `Page`; 404 trashed, 409 when the status is already Published — also when its `unpublish_at` has passed and the page is off the site, which is unpublished in the admin first — 422 `translations.{locale}.name` when a frontend-active locale has no name (no fallback to another locale or to `seo.title`). Without `published_at` a page keeps an earlier publish's date, else is stamped now; a future one schedules |
| `GET /types/{type}/fields` | read | `[Field]` for a base type or subtype key; 404 unknown, 403 when its base type is not writable for the token |
| `GET /locales` | read | `{default, active: [locale]}` — `active` is the frontend-active locales publish checks |
| `POST /media` | media | 201 `{id, url}`; 403 for a token with no page, tree or parent. `url` (http/https, downloaded through the admin's SSRF guard and size cap), plus `name?`, `alt?`. No file upload: the caller hands over a public URL. A refused URL or file type is 422 with the media library's own message |

**Write body** — `POST` takes exactly `parent_id, translations, image, og_image, fields`; `PATCH` the same without `parent_id`, every key optional. Any other top-level key is 422; a known key outside the token's field groups is 403 (see above). Blocks (`content`) are not written or returned in v1.


```json
{
  "parent_id": 12,
  "translations": {"en": {"name": "…", "slug": "…", "heading": "…", "summary": "…", "body": "<p>…</p>",
                          "seo": {"title": "…", "description": "…", "focus_keyword": "…", "og_title": "…", "og_description": "…", "canonical": "https://…"}}},
  "image": 41, "og_image": 41,
  "fields": {"cardLabel": {"en": "…"}, "steps": ["…"], "poster": 41}
}
```

`translations` keys are the content locales; `name` in the default locale is
required on create. There is no `title`: the page title moved to the SEO
tab's meta title (UPGRADING, "The page `title` field moved"), so the
browser-tab title is `seo.title`, the H1 `heading`, and `name` the page's own
text the admin requires — the list label, breadcrumb, link text and the slug
source, and the meta title when `seo.title` is empty. A `title` key is 422,
and a page's deprecated stored `title` is not returned. `body` and any
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
