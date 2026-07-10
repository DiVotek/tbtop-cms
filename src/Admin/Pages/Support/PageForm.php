<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageForm
{
    /**
     * Fields for the pages-list create modal. A deliberately small subset —
     * heading/summary/is_indexable/sort_order/blocks are edit-only; is_indexable
     * defaults to true server-side on create (see fillAttributes()), and
     * sort_order/blocks are refined after the record exists.
     *
     * @return list<Node>
     */
    public static function fields(S $s): array
    {
        return [
            $s->section(['title' => __('tbtop-cms::pages.sections.content')], [
                $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
                $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable(),
                $s->slug('slug')->label(__('tbtop-cms::pages.fields.slug'))->required()
                    ->translatable()->fromField('name'),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.settings')], [
                $s->select('type')->label(__('tbtop-cms::pages.fields.type'))->required()->options(PageFormOptions::types()),
                $s->select('parent_id')->label(__('tbtop-cms::pages.fields.parent'))->options(PageFormOptions::parents())
                    ->hiddenIf(Cond::notIn('type', ['category', 'article'])),
                $s->select('status')->label(__('tbtop-cms::pages.fields.status'))->required()->options(PageFormOptions::statuses()),
            ]),
        ];
    }

    /**
     * Fields for the "Основне" tab of the dedicated edit page.
     *
     * @return list<Node|Field>
     */
    public static function generalFields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
            $s->text('title')->label(__('tbtop-cms::pages.fields.title'))->translatable(),
            $s->text('heading')->label(__('tbtop-cms::pages.fields.heading'))->translatable(),
            $s->textarea('summary')->label(__('tbtop-cms::pages.fields.summary'))->translatable(),
            $s->select('type')->label(__('tbtop-cms::pages.fields.type'))->required()->options(PageFormOptions::types()),
            $s->select('parent_id')->label(__('tbtop-cms::pages.fields.parent'))->options(PageFormOptions::parents()),
            $s->select('status')->label(__('tbtop-cms::pages.fields.status'))->required()->options(PageFormOptions::statuses()),
            $s->number('sort_order')->label(__('tbtop-cms::pages.fields.sort_order')),
            BlockContentField::make($s),
        ];
    }

    /**
     * Fields for the "SEO" tab of the dedicated edit page.
     *
     * @return list<Field>
     */
    public static function seoFields(S $s): array
    {
        return [
            $s->text('seo_title')->label(__('tbtop-cms::pages.fields.seo_title')),
            $s->textarea('seo_description')->label(__('tbtop-cms::pages.fields.seo_description')),
            $s->checkbox('is_indexable')->label(__('tbtop-cms::pages.fields.is_indexable')),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Page
    {
        $page = $id !== null ? Page::query()->findOrFail($id) : new Page;
        $isCreate = ! $page->exists;
        $parentId = self::nullableInt($form['parent_id'] ?? null);
        self::guardParent($page, $parentId);
        self::guardTypeAndStatus($page, $form);

        $page->fill(self::fillAttributes($page, self::withTitleFallback($form), $parentId, $isCreate));

        if ($isCreate) {
            $page->pendingSlugOverrides = self::stringMap($form['slug'] ?? null);
        }

        $page->save();

        return $page;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function fillAttributes(Page $page, array $form, ?int $parentId, bool $isCreate): array
    {
        $attributes = [
            'name' => $form['name'] ?? [],
            'title' => $form['title'] ?? [],
            'heading' => $form['heading'] ?? [],
            'summary' => $form['summary'] ?? [],
            // The create form has no is_indexable field — new pages are always
            // indexable by default; edits keep the form-submitted value.
            'is_indexable' => $isCreate ? true : (bool) ($form['is_indexable'] ?? false),
            'sort_order' => (int) ($form['sort_order'] ?? 0),
            'content' => BlockContentField::toEnvelope($form['content'] ?? null),
            'seo' => self::seoAttribute($form),
        ];

        if ($page->exists && $page->is_system) {
            return $attributes;
        }

        return [
            ...$attributes,
            'type' => (string) ($form['type'] ?? ''),
            'parent_id' => $parentId,
            'status' => (string) ($form['status'] ?? PageStatus::Draft->value),
        ];
    }

    /**
     * When a locale's title is empty but its name is set, fall the title
     * back to the name — the create form no longer collects heading/summary
     * but title still drives <title>/OG tags, so it should not stay blank.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function withTitleFallback(array $form): array
    {
        $name = self::stringMap($form['name'] ?? null);
        $title = self::stringMap($form['title'] ?? null);

        foreach ($name as $locale => $value) {
            if ($value !== '' && ($title[$locale] ?? '') === '') {
                $title[$locale] = $value;
            }
        }

        $form['title'] = $title;

        return $form;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];

        foreach ($value as $locale => $entry) {
            if (is_string($locale)) {
                $map[$locale] = (string) $entry;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array{title: string, description: string}
     */
    private static function seoAttribute(array $form): array
    {
        return [
            'title' => (string) ($form['seo_title'] ?? ''),
            'description' => (string) ($form['seo_description'] ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $page = Page::query()->with('slugs')->findOrFail($id);
        $seo = is_array($page->seo) ? $page->seo : [];

        return [
            'name' => $page->getTranslations('name'),
            'title' => $page->getTranslations('title'),
            'heading' => $page->getTranslations('heading'),
            'summary' => $page->getTranslations('summary'),
            'type' => $page->type,
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : PageFormOptions::NO_PARENT,
            'status' => $page->status->value,
            'is_indexable' => $page->is_indexable,
            'sort_order' => $page->sort_order,
            'paths' => $page->slugs->pluck('path', 'locale')->all(),
            'content' => BlockContentField::toRows($page->content),
            'seo_title' => (string) ($seo['title'] ?? ''),
            'seo_description' => (string) ($seo['description'] ?? ''),
        ];
    }

    private static function guardParent(Page $page, ?int $parentId): void
    {
        if ($parentId === null || ! $page->exists) {
            return;
        }

        if ($parentId === $page->getKey() || in_array($parentId, self::descendantIds($page), true)) {
            throw ValidationException::withMessages([
                'parent_id' => 'A page cannot be placed under itself or one of its descendants.',
            ]);
        }
    }

    /** @param array<string, mixed> $form */
    private static function guardTypeAndStatus(Page $page, array $form): void
    {
        if ($page->exists && $page->is_system) {
            return;
        }

        $type = (string) ($form['type'] ?? '');
        if (! app(PageTypeRegistry::class)->has($type)) {
            throw ValidationException::withMessages([
                'type' => "Page type [{$type}] is not registered.",
            ]);
        }

        self::guardStatus((string) ($form['status'] ?? PageStatus::Draft->value));
    }

    private static function guardStatus(string $status): void
    {
        if (PageStatus::tryFrom($status) === null) {
            throw ValidationException::withMessages([
                'status' => "Status [{$status}] is not a valid page status.",
            ]);
        }
    }

    /** @return list<int> */
    private static function descendantIds(Page $page): array
    {
        $ids = [];
        $frontier = [$page->getKey()];

        while ($frontier !== []) {
            $children = Page::query()->whereIn('parent_id', $frontier)->pluck('id')->all();
            $children = array_values(array_diff($children, $ids));
            $ids = [...$ids, ...$children];
            $frontier = $children;
        }

        return array_map(static fn (mixed $id): int => (int) $id, $ids);
    }

    private static function nullableInt(mixed $value): ?int
    {
        if (in_array($value, [null, '', PageFormOptions::NO_PARENT], true)) {
            return null;
        }

        return (int) $value;
    }
}
