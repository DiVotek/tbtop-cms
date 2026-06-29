<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

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
        ];

        if ($isEdit) {
            $sections[] = $s->section(['title' => 'URLs'], [
                $s->text('paths')->label('Path')->translatable()->meta('disabled', true),
            ]);
        }

        return $sections;
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Page
    {
        $page = $id !== null ? Page::query()->findOrFail($id) : new Page;
        $parentId = self::nullableInt($form['parent_id'] ?? null);
        self::guardParent($page, $parentId);

        $page->fill(self::fillAttributes($page, $form, $parentId));
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

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $page = Page::query()->with('slugs')->findOrFail($id);

        return [
            'name' => $page->getTranslations('name'),
            'title' => $page->getTranslations('title'),
            'heading' => $page->getTranslations('heading'),
            'summary' => $page->getTranslations('summary'),
            'type' => $page->type,
            'parent_id' => $page->parent_id !== null ? (string) $page->parent_id : '',
            'status' => $page->status->value,
            'is_indexable' => $page->is_indexable,
            'sort_order' => $page->sort_order,
            'paths' => $page->slugs->pluck('path', 'locale')->all(),
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
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
