# Where page content goes

> Part of the host-builder guide. Start at [./README.md](./README.md).

Read this before you model a page type, write a seeder or import a site. It
decides where each piece of a page's content lives; the mechanics are in
[./pages.md](./pages.md) and [./blocks.md](./blocks.md), the end-to-end
recipe for a typed page in
[./recipes.md](./recipes.md#a-page-type-with-a-fixed-frame-and-a-blocks-slot).

## The rule — first match wins

Take each piece of content a page shows and walk the list top to bottom; it
goes where it first matches.

1. **The page's own text and image → the standard page fields.** Every page
   has them on its General tab, whatever its type, and every page ships them
   as `page.*` props (`PageController::props()`):

   | Field | Holds | Also used for |
   |---|---|---|
   | `name` | the page's short name | admin lists and tree, breadcrumb label, the label of a page link or `pages`-field entry; `title` falls back to it |
   | `title` | the browser-tab title | the meta title when the SEO tab sets none (then `name`) |
   | `heading` | the H1 drawn on the page | the SEO checklist's keyword-in-H1 check |
   | `summary` | a short plain-text lead or teaser | the meta description when the SEO tab sets none |
   | `body` (admin "Content") | the page's main rich text — its description | shipped as `page.body` HTML (this locale, else the default one, `''` when empty); `ResolveContext::pageBodyHtml()` in a block |
   | `image` | the page's main picture (a media id) | `page.image` (URL); og:image and the sitemap image when the SEO tab names none |

2. **Extra per-page data the standard fields cannot hold → a type field** in
   the type's `fields()`. Declare it on the subtype when only that kind of
   page has it. A type field never stands in for a standard one: the page's
   description is `body`, its picture is `image`. A second text the design
   puts somewhere else (a lead above a form, a note under a price) is a type
   field; the main description is still `body`.
3. **Links to other pages → a `pages` field** in the type's `fields()`; the
   list on the other side → `backReferences()`. The relation is edited on one
   side only ([pages.md](./pages.md#page-references)).
4. **Values computed from other pages or the tree** (child tiles, a sidebar
   tree, a reverse list with more than `{id, name, url}`, a value read off the
   parent) **→ a `resolveFields()` override.** The result ships in
   `page.fields`; nothing is stored and no editor types it.
5. **Markup every page of the type has** (banner, profile card, sidebar, the
   layout around the description) **→ the type's template in host React**,
   picked by `page.subtype` (or `page.type`) in the host `CmsPage.tsx` and
   drawn from `page.*` and `page.fields`. The page's blocks render in a slot
   inside it.
6. **Optional sections an editor adds, removes or reorders per page →
   blocks**, offered on the type with `placements()`.
7. **Content identical on every page → a block in the `header`/`footer`
   layout slot.** Identical on a set of pages the editor picks → a shared
   block placed on each. Fixed labels a template prints ("Specialists",
   "Related services") → host translations in the template, not content.
8. **`template()` → only for a source-backed type**, or as the default
   content of a page whose `content` column is null. It is not the frame
   mechanism:
   - `PageController` renders `$page->content ?? $type->template($page) ??
     []`. The admin writes a list to `content` on every create and save of a
     non-source-backed page (`PageForm::fillContent()`, `[]` included), so on
     an admin-made page `template()` never renders; on a seeded page it
     disappears at the first save.
   - It returns block envelopes, so the fixed parts become blocks again —
     the thing rule 5 avoids.
   - A form block in it cannot be submitted: `FormBlockLocator` searches the
     page's stored `content` and the layout slots, never `template()`.

## Wrong placements seen in real imports

- **Every fixed part as a block.** Banner, description, a list computed from
  back-references, child tiles, a sidebar tree and a profile card each became
  a block, the per-type copy became shared blocks, and editors attached them
  to every new page by hand. Right: rules 1, 4 and 5 — the template draws
  them from `page.*` and `page.fields`; blocks are only the optional
  sections.
- **The description in a type field.** A type declared `intro` (rich text)
  for the page's description; `body` stayed empty on almost every page,
  and pixel checks passed because the template drew `intro`. Right: rule 1 —
  the description is `body`; a type field is only for a second text placed
  elsewhere.
- **`template()` as the frame.** The type's fixed parts were returned from
  `template()`; they showed on seeded pages and vanished from each page the
  first time an editor saved it. Right: rule 5.

## After modelling or importing: count the fills

Rendering checks (pixel parity, smoke tests) do not show where text is
stored. After a model change, a seeder or an import, count per type how many
pages fill each field, in the default locale:

```php
// php artisan tinker
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageTranslation;

$locale = app(Languages::class)->defaultLocale();
$filled = fn (mixed $v): bool => is_array($v) ? array_filter($v, fn ($x) => $x !== null && $x !== '' && $x !== []) !== [] : $v !== null && $v !== '';

Page::query()->get()
    ->groupBy(fn (Page $p): string => $p->type.($p->subtype !== null ? '/'.$p->subtype : ''))
    ->map(fn ($pages): array => [
        'pages' => $pages->count(),
        ...collect(['heading', 'summary', 'body'])->mapWithKeys(fn (string $f): array => [
            $f => $pages->filter(fn (Page $p): bool => PageTranslation::get($p, $f, $locale, $locale) !== null)->count(),
        ])->all(),
        'image' => $pages->filter(fn (Page $p): bool => $filled($p->image))->count(),
        'fields' => $pages->flatMap(fn (Page $p): array => array_keys(array_filter($p->fields ?? [], $filled)))->countBy()->all(),
    ])
    ->all();
```

Read it against the rule:

- `body` empty on most pages of a type while a type field is full → the
  description went into a type field (rule 1).
- `image` empty while a type field holds a picture → same, for `image`.
- A type field full on every page with the same value → it is copy for the
  template or a layout slot (rule 7), not per-page data.

A rich-text type field holding an empty editor document counts as filled
here; spot-check those.
