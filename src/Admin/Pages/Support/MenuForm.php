<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Fields\Repeater;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Menu\Models\Menu;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * Fields + JSON round-trip for the site-menu editor. Items are a two-level
 * tree — each item is one of three types (page ref / raw url / on-page
 * anchor); a `page` item stores `page_id` so links stay rename-safe. The
 * child repeater is capped at one level: sub-items cannot nest further.
 */
class MenuForm
{
    /** @return list<Node> */
    public static function fields(S $s): array
    {
        return [
            $s->section(['title' => __('tbtop-cms::menus.sections.menu')], [
                $s->text('name')->label(__('tbtop-cms::menus.fields.name'))->required(),
                $s->text('key')->label(__('tbtop-cms::menus.fields.key'))->required()
                    ->helperText(__('tbtop-cms::menus.fields.key_hint')),
            ]),
            $s->section(['title' => __('tbtop-cms::menus.sections.items')], [
                self::itemsRepeater($s),
            ]),
        ];
    }

    /**
     * The top-level `items` repeater. Each row carries the shared item fields
     * plus a nested `children` repeater — the one and only nested level. The
     * child repeater holds the same item fields but no `children` of its own,
     * so the tree is hard-capped at two levels in the UI.
     */
    private static function itemsRepeater(S $s): Repeater
    {
        $childItems = $s->repeater('children')->label(__('tbtop-cms::menus.fields.children'))
            ->collapsible()->summary('label')
            ->fields(self::itemFields($s));

        return $s->repeater('items')->label(__('tbtop-cms::menus.fields.items'))
            ->collapsible()->summary('label')
            ->fields([...self::itemFields($s), $childItems]);
    }

    /**
     * Fields shared by every menu item (both levels). Type-specific fields
     * are hidden unless the matching type is selected.
     *
     * @return list<Field>
     */
    private static function itemFields(S $s): array
    {
        return [
            $s->text('label')->label(__('tbtop-cms::menus.fields.label'))->required(),
            $s->select('type')->label(__('tbtop-cms::menus.fields.type'))->required()
                ->options([
                    ['value' => 'page', 'label' => __('tbtop-cms::menus.types.page')],
                    ['value' => 'url', 'label' => __('tbtop-cms::menus.types.url')],
                    ['value' => 'anchor', 'label' => __('tbtop-cms::menus.types.anchor')],
                ]),
            $s->select('page_id')->label(__('tbtop-cms::menus.fields.page'))
                ->options(self::pageOptions())
                ->hiddenIf('type', '!=', 'page'),
            $s->text('url')->label(__('tbtop-cms::menus.fields.url'))
                ->hiddenIf('type', '!=', 'url'),
            $s->text('anchor')->label(__('tbtop-cms::menus.fields.anchor'))
                ->helperText(__('tbtop-cms::menus.fields.anchor_hint'))
                ->hiddenIf('type', '!=', 'anchor'),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Menu
    {
        $menu = $id !== null ? Menu::query()->findOrFail($id) : new Menu;
        $menu->fill([
            'name' => (string) ($form['name'] ?? ''),
            'key' => (string) ($form['key'] ?? ''),
            'items' => self::normalizeItems($form['items'] ?? null, depth: 0),
        ]);
        $menu->save();

        return $menu;
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $menu = Menu::query()->findOrFail($id);

        return [
            'name' => $menu->name,
            'key' => $menu->key,
            'items' => $menu->items ?? [],
        ];
    }

    /**
     * Drop empty rows and strip fields that do not belong to the chosen type,
     * recursing one level into children. Depth is hard-capped at 1 so a
     * malformed payload can never persist a third level.
     *
     * @return list<array<string, mixed>>
     */
    private static function normalizeItems(mixed $rows, int $depth): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $type = in_array($row['type'] ?? null, ['page', 'url', 'anchor'], true)
                ? $row['type']
                : 'page';

            $item = ['type' => $type, 'label' => $label];

            if ($type === 'page' && isset($row['page_id']) && $row['page_id'] !== '') {
                $item['page_id'] = (int) $row['page_id'];
            } elseif ($type === 'url') {
                $item['url'] = (string) ($row['url'] ?? '');
            } elseif ($type === 'anchor') {
                $item['anchor'] = (string) ($row['anchor'] ?? '');
            }

            if ($depth < 1) {
                $children = self::normalizeItems($row['children'] ?? null, $depth + 1);
                if ($children !== []) {
                    $item['children'] = $children;
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    /** @return list<array{value: string, label: string}> */
    public static function pageOptions(): array
    {
        $default = app(Languages::class)->defaultLocale();
        $options = [];

        $pages = Page::query()
            ->where('status', PageStatus::Published->value)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        foreach ($pages as $page) {
            $name = $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey());
            $options[] = ['value' => (string) $page->getKey(), 'label' => $name];
        }

        return $options;
    }
}
