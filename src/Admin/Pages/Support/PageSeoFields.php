<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\Rule;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Frontend\Breadcrumbs;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaSelector;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaTypeRegistry;
use Tbtop\Cms\Frontend\Seo\SeoChecklist;
use Tbtop\Cms\Frontend\Seo\SeoResolver;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/** The page editor's SEO tab, behind PageForm::seoFields(). */
final class PageSeoFields
{
    /**
     * Fields for the "SEO" tab of the dedicated edit page. is_indexable lives
     * in the sidebar's "Publication" card (PageEditSidebar), not here.
     *
     * seo_title/seo_description/seo_focus_keyword/og_title/og_description are
     * translatable — hreflang variants of the same page routinely want a
     * different meta title/description per locale. og_image stays a plain
     * scalar: an image isn't per-locale, and hreflang pages share it.
     *
     * Placeholders show the COMPUTED final value (template/suffix applied)
     * for the DEFAULT locale only — a static, single-per-field hint (same
     * string in every locale tab) rather than a live per-locale preview; see
     * computedPlaceholders() below. $page is only absent for the create
     * modal, which never renders this tab today, but the guard keeps the
     * method safe if that ever changes.
     *
     * @return list<Node|Field>
     */
    public static function build(S $s, ?Page $page = null): array
    {
        $placeholders = self::computedPlaceholders($page);
        // url:http,https rather than bare 'url': the bare rule accepts any
        // scheme a browser tolerates (javascript:, mailto:, ftp:), and one of
        // those saved as a canonical would either do nothing or do something
        // actively wrong when a crawler or the head tag reads it back out.
        $canonical = $s->text('seo_canonical')->label(__('tbtop-cms::pages.fields.seo_canonical'))
            ->translatable()->rules(['nullable', 'url:http,https'])
            ->helperText(__('tbtop-cms::pages.fields.seo_canonical_hint'));
        self::applyLocaleRules($canonical, ['nullable', 'url:http,https']);

        $fields = [
            $s->text('seo_title')->label(__('tbtop-cms::pages.fields.seo_title'))
                ->translatable()->placeholder($placeholders['title'])
                ->helperText(__('tbtop-cms::pages.fields.seo_title_hint')),
            $s->textarea('seo_description')->label(__('tbtop-cms::pages.fields.seo_description'))
                ->translatable()->set('placeholder', $placeholders['description'])
                ->helperText(__('tbtop-cms::pages.fields.seo_description_hint')),
            $s->text('seo_focus_keyword')->label(__('tbtop-cms::pages.fields.seo_focus_keyword'))
                ->translatable()
                ->helperText(__('tbtop-cms::pages.fields.seo_focus_keyword_hint')),
            $s->section(['title' => __('tbtop-cms::pages.sections.social')], [
                ...OgPreview::build($s, $page, app(Languages::class)->defaultLocale()),
                // og:title/og:description fall back to the UNtemplated meta
                // values (see SeoResolver: ogTitle uses metaTitle, not
                // title()), so their placeholders show ogTitle/ogDescription
                // rather than the templated outputs.
                $s->text('og_title')->label(__('tbtop-cms::pages.fields.og_title'))
                    ->translatable()->placeholder($placeholders['og_title']),
                $s->textarea('og_description')->label(__('tbtop-cms::pages.fields.og_description'))
                    ->translatable()->set('placeholder', $placeholders['og_description']),
                $s->media('og_image')->label(__('tbtop-cms::pages.fields.og_image'))
                    ->variant('preview'),
            ]),
            $canonical,
            ...self::schemaSection($s, $page),
        ];

        $checklist = self::seoChecklistSection($s, $page);

        return [
            // The preview spans the row: its card is as wide as the result
            // column it measures (600px plus padding) and cannot be narrowed
            // without the pixel meter describing a box nobody can see. It
            // renders collapsed, so what actually sits above the fields is one
            // line carrying both numbers.
            ...SerpPreview::build($s, $page, app(Languages::class)->defaultLocale()),
            // Fields left, checks right — the report about the work stops
            // standing between the editor and the work. The checklist reads as
            // short labelled rows, so a third of the row is enough for it, and
            // one column below `lg` puts it back under the fields rather than
            // squeezing both.
            $s->grid(['cols' => ['sm' => 1, 'lg' => 3], 'gap' => 6], [
                $s->stack($fields, ['colSpan' => ['sm' => 1, 'lg' => 2]]),
                ...$checklist,
            ]),
        ];
    }

    /**
     * The structured-data section. What it offers is decided by the page
     * type, which the server already knows — an article is always an Article
     * and a category always a CollectionPage, so neither gets a type picker,
     * and a category needs no fields at all (its hasPart listing comes from
     * the child pages). Only a page type with more than one schema key
     * carries the select at all — one choice is nothing to choose from.
     *
     * Absent for the create modal ($page null/unsaved) for the same reason
     * the checklist is: it renders no SEO tab today.
     *
     * @return list<Node|Field>
     */
    private static function schemaSection(S $s, ?Page $page): array
    {
        if ($page === null || ! $page->exists || ! app(PageTypeRegistry::class)->has($page->type)) {
            return [];
        }

        $keys = app(SchemaSelector::class)->availableKeys($page);

        if (count($keys) < 2) {
            return [];
        }

        $registry = app(SchemaTypeRegistry::class);
        $options = array_map(
            static fn (string $key): array => ['value' => $key, 'label' => $registry->get($key)->label()],
            $keys,
        );

        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.schema')], [
                $s->select('schema_type')->label(__('tbtop-cms::pages.fields.schema_type'))->options($options)
                    ->rules(['nullable', Rule::in($keys)]),
            ]),
        ];
    }

    /**
     * RuleWalker applies a translatable field's own ->rules() to the default
     * locale only (see RuleWalker::fromTranslatableField()); every other
     * active locale needs the same rules via ->rulesForLocale() explicitly,
     * or it would validate as bare 'nullable' and accept anything. Locales
     * come from the same Languages source ContentLocales syncs into
     * config('tbtop-admin.content_locales') for this request.
     *
     * @param  list<string>  $rules
     */
    private static function applyLocaleRules(Field $field, array $rules): Field
    {
        $languages = app(Languages::class);
        $default = $languages->defaultLocale();

        foreach ($languages->adminActive() as $row) {
            if ($row['locale'] !== $default) {
                $field->rulesForLocale($row['locale'], $rules);
            }
        }

        return $field;
    }

    /**
     * Read-only checklist, rendered beside the SEO fields. Computed for every
     * editor locale at once (see EditorLocales::list()), same set SerpPreview
     * resolves — the client picks the active tab's entry, same reasoning as
     * that block's docblock. Absent for the create modal ($page null/
     * unsaved): there is nothing to check yet, and duplicate-title comparison
     * needs a persisted id to exclude itself from the query.
     *
     * Deliberately NOT a liveRegion, unlike SerpPreview/OgPreview: the
     * duplicate-title check runs a database query (SeoChecklist::
     * duplicateTitleCheck()), and every other check reads facts that only
     * change on save (is_indexable, hreflang coverage, the saved og_image) —
     * recomputing a DB query on every keystroke a live region would trigger
     * buys nothing an editor would notice mid-edit. The checklist catches up
     * on save or reload, which is when its facts actually change.
     *
     * A custom node kind rather than display blocks: displayValue() and
     * displayText() are separate blocks stacked vertically, so every check
     * cost two rows — 24 lines for 12 checks, most of the tab's height. The
     * client block puts the status and the label on one line and groups them
     * by whether the check is required.
     *
     * Values travel as data, not markup, so nothing here needs escaping:
     * React renders them as text nodes.
     *
     * @return list<Node>
     */
    private static function seoChecklistSection(S $s, ?Page $page): array
    {
        if ($page === null || ! $page->exists) {
            return [];
        }

        $defaultLocale = app(Languages::class)->defaultLocale();
        $checklist = app(SeoChecklist::class);

        $byLocale = [];
        foreach (EditorLocales::list($defaultLocale) as $locale) {
            $byLocale[$locale] = array_map(
                static fn (array $check): array => [
                    ...$check,
                    'statusWord' => __('tbtop-cms::pages.seo_checklist.status.' . $check['status']),
                ],
                $checklist->for($page, $locale),
            );
        }

        return [
            new Node('cms:seoChecklist', [
                'byLocale' => $byLocale,
                'defaultLocale' => $defaultLocale,
                'labels' => [
                    'required' => __('tbtop-cms::pages.seo_checklist.groups.required'),
                    'optional' => __('tbtop-cms::pages.seo_checklist.groups.optional'),
                ],
            ]),
        ];
    }

    /**
     * Computed values a blank SEO field would actually resolve to, for the
     * default locale — reuses SeoResolver rather than re-deriving the
     * title/description cascade a second time. null $page (create modal, not
     * reached today) or an unsaved record yields empty placeholders.
     *
     * @return array{title: string, description: string, og_title: string, og_description: string}
     */
    private static function computedPlaceholders(?Page $page): array
    {
        if ($page === null || ! $page->exists) {
            return ['title' => '', 'description' => '', 'og_title' => '', 'og_description' => ''];
        }

        $locale = app(Languages::class)->defaultLocale();
        // Breadcrumbs passed for the same reason SerpPreview and SeoChecklist
        // pass them: a placeholder promising what an empty field resolves to
        // must not drop a {parent} segment the frontend renders.
        $resolved = app(SeoResolver::class)->resolve(
            $page,
            $locale,
            app(Breadcrumbs::class)->ancestors($page, $locale),
        );

        return [
            'title' => $resolved['title'],
            'description' => $resolved['description'],
            'og_title' => $resolved['ogTitle'],
            'og_description' => $resolved['ogDescription'],
        ];
    }
}
