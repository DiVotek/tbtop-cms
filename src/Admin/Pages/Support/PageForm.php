<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageForm
{
    /** @return list<Node> */
    public static function fields(S $s, bool $isEdit): array
    {
        $sections = [
            $s->section(['title' => 'Content'], [
                $s->text('name')->label('Name')->required()->translatable(),
                $s->text('title')->label('Title')->translatable(),
                $s->text('heading')->label('Heading')->translatable(),
                $s->textarea('summary')->label('Summary')->translatable(),
            ]),
            $s->section(['title' => 'Settings'], [
                $s->select('type')->label('Type')->required()->options(PageFormOptions::types()),
                $s->select('parent_id')->label('Parent')->options(PageFormOptions::parents()),
                $s->select('status')->label('Status')->required()->options(PageFormOptions::statuses()),
                $s->checkbox('is_indexable')->label('Indexable in search'),
                $s->number('sort_order')->label('Sort order'),
            ]),
            BlockContentField::make($s),
        ];

        if ($isEdit) {
            $sections[] = $s->section(['title' => 'URLs'], [
                $s->text('paths')->label('Path')->translatable()->meta('disabled', true),
            ]);
        }

        return $sections;
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

    /**
     * Row-action "quick edit" fields — a small whitelisted subset.
     *
     * @return list<Field>
     */
    public static function quickFields(S $s): array
    {
        return [
            $s->text('name')->label(__('tbtop-cms::pages.fields.name'))->required()->translatable(),
            $s->select('status')->label(__('tbtop-cms::pages.fields.status'))->required()->options(PageFormOptions::statuses()),
            $s->select('parent_id')->label(__('tbtop-cms::pages.fields.parent'))->options(PageFormOptions::parents()),
            $s->number('sort_order')->label(__('tbtop-cms::pages.fields.sort_order')),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Page
    {
        $page = $id !== null ? Page::query()->findOrFail($id) : new Page;
        $parentId = self::nullableInt($form['parent_id'] ?? null);
        self::guardParent($page, $parentId);
        self::guardTypeAndStatus($page, $form);

        $page->fill(self::fillAttributes($page, $form, $parentId));
        $page->save();

        return $page;
    }

    /**
     * Partial save for the list's quick-edit modal. Whitelisted to name/status/
     * parent/sort_order only — every other attribute (heading, summary, content,
     * seo, ...) is left untouched, unlike save()'s full fillAttributes().
     *
     * @param  array<string, mixed>  $form
     */
    public static function saveQuick(array $form, int $id): Page
    {
        $page = Page::query()->findOrFail($id);
        $parentId = self::nullableInt($form['parent_id'] ?? null);
        self::guardParent($page, $parentId);

        $attributes = ['name' => $form['name'] ?? [], 'sort_order' => (int) ($form['sort_order'] ?? 0)];

        if (! $page->is_system) {
            $status = (string) ($form['status'] ?? $page->status->value);
            self::guardStatus($status);
            $attributes['parent_id'] = $parentId;
            $attributes['status'] = $status;
        }

        $page->fill($attributes);
        $page->save();

        return $page;
    }

    /**
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    private static function fillAttributes(Page $page, array $form, ?int $parentId): array
    {
        $attributes = [
            'name' => $form['name'] ?? [],
            'title' => $form['title'] ?? [],
            'heading' => $form['heading'] ?? [],
            'summary' => $form['summary'] ?? [],
            'is_indexable' => (bool) ($form['is_indexable'] ?? false),
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

    /**
     * Fields for the list's quick-edit modal.
     *
     * @return array<string, mixed>
     */
    public static function loadQuickRow(int $id): array
    {
        $page = Page::query()->findOrFail($id);

        return [
            'name' => $page->getTranslations('name'),
            'status' => $page->status->value,
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : PageFormOptions::NO_PARENT,
            'sort_order' => $page->sort_order,
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
        if ($value === null || $value === '' || $value === PageFormOptions::NO_PARENT) {
            return null;
        }

        return (int) $value;
    }
}
