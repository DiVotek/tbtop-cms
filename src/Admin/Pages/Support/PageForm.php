<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Admin\Fields\BlockContent;
use Tbtop\Cms\Frontend\Seo\Schema\SchemaSelector;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Types\PageType;

class PageForm
{
    /** The create form's per-base subtype selects are named `subtype_{base}`; save() reads the picked type's one. */
    public const string CREATE_SUBTYPE_PREFIX = 'subtype_';

    /**
     * Fields for the pages-list create modal. A deliberately small subset —
     * heading/summary/is_indexable/sort_order/blocks/status are edit-only;
     * is_indexable defaults to true and status to draft server-side on create
     * (see PageFormAttributes::for()), and sort_order/blocks are refined after the
     * record exists.
     *
     * @return list<Node>
     */
    public static function fields(S $s): array
    {
        // A parent is always a category: an article's (required unless the host's
        // article type drops requiresParent()), a subcategory's optional one.
        $parent = $s->relation('parent_id')->label(__('tbtop-cms::pages.fields.category'))
            ->query(static fn (): Builder => self::parentQuery())
            ->labelKey('name')->searchable()
            ->hiddenIf(Cond::notIn('type', ['category', PageLeafType::ARTICLE]));
        if (PageLeafType::articleRequiresParent()) {
            $parent->requiredIf(Cond::eq('type', PageLeafType::ARTICLE));
        }

        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.content')], [
                $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name'),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.settings')], [
                // The generic page is what most creates are; category/article
                // are the deliberate picks. Source-backed types are excluded:
                // creating one manually is not allowed, the storefront sync
                // owns creation (see PageTypeRegistry::isSourceBacked()).
                $s->select('type')->label(__('tbtop-cms::pages.fields.type'))
                    ->required()->options(PageFormOptions::types(includeSourceBacked: false))
                    ->default(app(PageType::class)->key()),
                // One select per base that has subtypes, each shown only for its base: a
                // single `subtype` select cannot swap its options when "Type" changes.
                ...array_merge(...array_map(
                    static fn (array $type): array => self::subtypeField($s, $type['value'], self::CREATE_SUBTYPE_PREFIX . $type['value']),
                    PageFormOptions::types(includeSourceBacked: false),
                )),
                $parent,
            ]),
        ];
    }

    /**
     * The "Subtype" select for pages of $base: absent when $base has no
     * subtypes, hidden once "Type" is changed to another base — its options
     * would not belong to it, and save() drops the stale value. The edit
     * form's select ($isStored) stays until it is cleared: save() refuses a
     * type change that would leave a stored subtype behind, and the editor
     * needs the select to clear it.
     *
     * @return list<Field>
     */
    public static function subtypeField(S $s, string $base, string $name = 'subtype', bool $isStored = false): array
    {
        $options = PageFormOptions::subtypes($base);
        if ($options === []) {
            return [];
        }

        return [
            $s->select($name)->label(__('tbtop-cms::pages.fields.subtype'))
                ->options($options)->default('')
                ->hiddenIf($isStored ? Cond::all(Cond::neq('type', $base), Cond::empty($name)) : Cond::neq('type', $base)),
        ];
    }

    /**
     * Base query backing the parent_id relation picker: only categories are
     * valid parents (the sole grouping container in the page hierarchy — see
     * PageTypeRegistry/CategoryType). Edit forms pass the page being edited
     * so it and its descendants are excluded — placing a page under itself or
     * its own subtree is a cycle PageFormGuards::parent() would reject anyway, but
     * hiding those options keeps the picker from even offering them.
     *
     * @return Builder<Page>
     */
    public static function parentQuery(?Page $excluding = null): Builder
    {
        $query = Page::query()->where('type', 'category')->orderBy('sort_order')->orderBy('id');

        if ($excluding !== null && $excluding->exists) {
            $query->whereNotIn('id', [$excluding->getKey(), ...PageFormGuards::descendantIds($excluding)]);
        }

        return $query;
    }

    /**
     * Fields for the "Основне" tab of the dedicated edit page. status/type/
     * parent_id/sort_order live in the sidebar cards (PageEditSidebar); the
     * block content lives in its own per-block tabs (PageEditBlockTabs) —
     * neither belongs to this tab anymore.
     *
     * $withSlug is false for the site-root system page (Home: is_system with
     * no parent): its slug/path must stay '' (see PageSlugWriter::slugBase()/
     * baseFor()), but RuleWalker collects `required` statically from the
     * field tree, so a required slug field would make it unsaveable — and
     * separately, the client slug field auto-derives from the name when it
     * mounts over an empty value, which would propose "home" and move the
     * site root off '/' on save. Omitting the field entirely avoids both.
     *
     * @return list<Node|Field>
     */
    public static function generalFields(S $s, bool $withSlug = true): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable()
                ->helperText(__('tbtop-cms::pages.fields.name_hint')),
            ...($withSlug ? [
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name')
                    ->helperText(__('tbtop-cms::pages.fields.slug_edit_hint')),
            ] : []),
            $s->text('heading')->label(__('tbtop-cms::pages.fields.heading'))->translatable()
                ->helperText(__('tbtop-cms::pages.fields.heading_hint')),
            $s->textarea('summary')->label(__('tbtop-cms::pages.fields.summary'))->translatable(),
            $s->richtext('body')->label(__('tbtop-cms::pages.fields.body'))->translatable(),
        ];
    }

    /**
     * Fields for the "SEO" tab of the dedicated edit page; see PageSeoFields.
     *
     * @return list<Node|Field>
     */
    public static function seoFields(S $s, ?Page $page = null): array
    {
        return PageSeoFields::build($s, $page);
    }

    /**
     * @param  array<string, mixed>  $form
     *
     * @throws PageConflictException When $form carries a lock_version that no
     *                               longer matches the row (someone else saved or restored this
     *                               page since the form was loaded). Checked first, before any
     *                               fill/guard side effect, so a stale submit never partially
     *                               applies, and again at write time by PageObserver's atomic
     *                               claim, which a save racing this one loses — see
     *                               PageEditPage::save()/PageEditBlockTabs for how
     *                               callers turn this into a non-destructive error notify that
     *                               keeps the submitter's unsaved edits in the form.
     */
    public static function save(array $form, ?int $id = null): Page
    {
        return PageFormWriter::save($form, $id);
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $page = Page::query()->with('slugs')->findOrFail($id);
        $seo = is_array($page->seo) ? $page->seo : [];

        return [
            'name' => $page->getTranslations('name'),
            'heading' => $page->getTranslations('heading'),
            'summary' => $page->getTranslations('summary'),
            'body' => $page->getTranslations('body'),
            'type' => $page->type,
            'subtype' => $page->subtype ?? '',
            // Raw id string (or null) — the relation picker resolves its
            // label via the relation-search endpoint's resolve mode, which
            // looks the value up by primary key; the old NO_PARENT sentinel
            // would 404 there instead of rendering an empty picker.
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : null,
            'status' => $page->status->value,
            'published_at' => $page->published_at?->toIso8601String(),
            'unpublish_at' => $page->unpublish_at?->toIso8601String(),
            'is_indexable' => $page->is_indexable,
            'sort_order' => $page->sort_order,
            'paths' => $page->slugs->pluck('path', 'locale')->all(),
            'slug' => $page->slugs->pluck('slug', 'locale')->all(),
            'content' => BlockContent::toRows($page->content),
            'image' => (string) ($page->image ?? ''),
            // Passed through raw (scalar or locale map) rather than cast to
            // string: TranslatableRecord::normalize() expands a legacy
            // scalar into a locale map client-side (see the field's
            // ->translatable() docs), so casting here would collapse an
            // already-translated map back into a single string.
            'seo_title' => $seo['title'] ?? '',
            'seo_description' => $seo['description'] ?? '',
            'seo_focus_keyword' => $seo['focus_keyword'] ?? '',
            'og_title' => $seo['og_title'] ?? '',
            'og_description' => $seo['og_description'] ?? '',
            'og_image' => (string) ($seo['og_image'] ?? ''),
            'seo_canonical' => $seo['canonical'] ?? '',
            ...self::schemaRow($page),
            // Seeds the pageEditor form's hidden lock_version field (see
            // PageEditPage::view()). The client would round-trip an
            // undeclared record key on its own, but FormSubmitController's
            // $request->validate() strips keys RuleWalker collected no rule
            // for — the value only survives the forms endpoint because a
            // real (hidden) field exists for it in the tree.
            'lock_version' => $page->lock_version,
        ];
    }

    /**
     * Seeds `schema_type` with the effective key (via SchemaSelector), not
     * the raw stored value — a page saved before this key existed, or with a
     * key that drifted out of its type's schemaTypes(), opens showing the
     * type's actual default instead of an empty select.
     *
     * @return array{schema_type: string}
     */
    private static function schemaRow(Page $page): array
    {
        return ['schema_type' => app(SchemaSelector::class)->selectFor($page)->key()];
    }
}
