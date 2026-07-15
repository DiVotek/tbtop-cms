<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Cond;
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
    public static function fields(S $s, bool $isEdit = false): array
    {
        return [
            $s->section(['title' => __('tbtop-cms::menus.sections.menu')], [
                $s->text('name')->label(__('tbtop-cms::menus.fields.name'))->required(),
                self::keyField($s, $isEdit),
            ]),
            $s->section(['title' => __('tbtop-cms::menus.sections.items')], [
                self::itemsRepeater($s),
            ]),
        ];
    }

    /**
     * On create the key is a slug that auto-derives from the name (still
     * editable). Once saved the key is the stable reference other things use,
     * so on edit it is shown read-only — renaming the menu never moves it.
     */
    private static function keyField(S $s, bool $isEdit): Field
    {
        if ($isEdit) {
            return $s->text('key')->label(__('tbtop-cms::menus.fields.key'))->required()
                ->disabledIf(Cond::notEmpty('key'))
                ->helperText(__('tbtop-cms::menus.fields.key_locked_hint'));
        }

        return $s->slug('key')->label(__('tbtop-cms::menus.fields.key'))->required()
            ->fromField('name')
            ->helperText(__('tbtop-cms::menus.fields.key_hint'));
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
            ->fields(self::itemFields($s, 'items.*.children.*.type'));

        return $s->repeater('items')->label(__('tbtop-cms::menus.fields.items'))
            ->collapsible()->summary('label')
            ->fields([...self::itemFields($s, 'items.*.type'), $childItems]);
    }

    /**
     * Fields shared by every menu item (both levels). Type-specific fields are
     * hidden and required only when the matching type is selected. `$typePath`
     * is the full wildcard path to this level's `type` field — Laravel's
     * required_if needs the absolute sibling path to scope per row, so it
     * differs between the top level and the nested children.
     *
     * @return list<Field>
     */
    private static function itemFields(S $s, string $typePath): array
    {
        return [
            $s->text('label')->label(__('tbtop-cms::menus.fields.label'))->required(),
            $s->select('type')->label(__('tbtop-cms::menus.fields.type'))->required()
                ->options([
                    ['value' => 'page', 'label' => __('tbtop-cms::menus.types.page')],
                    ['value' => 'category', 'label' => __('tbtop-cms::menus.types.category')],
                    ['value' => 'url', 'label' => __('tbtop-cms::menus.types.url')],
                    ['value' => 'anchor', 'label' => __('tbtop-cms::menus.types.anchor')],
                ]),
            $s->select('page_id')->label(__('tbtop-cms::menus.fields.page'))
                ->options(self::pageOptions())
                ->rules("required_if:{$typePath},page")
                ->hiddenIf('type', '!=', 'page'),
            $s->select('category_id')->label(__('tbtop-cms::menus.fields.category'))
                ->options(self::categoryOptions())
                ->rules("required_if:{$typePath},category")
                ->hiddenIf('type', '!=', 'category'),
            $s->text('url')->label(__('tbtop-cms::menus.fields.url'))
                ->rules("required_if:{$typePath},url")
                ->hiddenIf('type', '!=', 'url'),
            $s->text('anchor')->label(__('tbtop-cms::menus.fields.anchor'))
                ->helperText(__('tbtop-cms::menus.fields.anchor_hint'))
                ->rules("required_if:{$typePath},anchor")
                ->hiddenIf('type', '!=', 'anchor'),
            $s->boolean('target_blank')->label(__('tbtop-cms::menus.fields.target_blank')),
        ];
    }

    /** @param array<string, mixed> $form */
    public static function save(array $form, ?int $id = null): Menu
    {
        $menu = $id !== null ? Menu::query()->findOrFail($id) : new Menu;
        $data = [
            'name' => (string) ($form['name'] ?? ''),
            'items' => self::normalizeItems($form['items'] ?? null, depth: 0),
        ];

        // The key is set once, at creation, and is immutable afterwards — it is
        // the stable reference other things resolve the menu by. On edit it is
        // shown read-only, so never trust an incoming value.
        if ($id === null) {
            $data['key'] = (string) ($form['key'] ?? '');
        }

        $menu->fill($data);
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

    /** The sub-field that carries each item type's link target. */
    private const TARGET_FIELDS = [
        'page' => 'page_id',
        'category' => 'category_id',
        'url' => 'url',
        'anchor' => 'anchor',
    ];

    /**
     * Drop empty rows and rows whose type has no target, keeping only the one
     * target field for the chosen type, recursing one level into children.
     * Depth is hard-capped at 1 so a malformed payload can never persist a
     * third level. An item without a resolvable target is dropped entirely —
     * "no target" is one consistent shape, never a null/empty placeholder.
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

            $type = in_array($row['type'] ?? null, ['page', 'category', 'url', 'anchor'], true)
                ? $row['type']
                : 'page';

            $targetField = self::TARGET_FIELDS[$type];
            $rawTarget = $row[$targetField] ?? null;
            $target = is_string($rawTarget) ? trim($rawTarget) : $rawTarget;
            if ($target === null || $target === '') {
                continue;
            }

            $item = [
                'type' => $type,
                'label' => $label,
                $targetField => in_array($type, ['page', 'category'], true) ? (int) $target : (string) $target,
            ];

            if (! empty($row['target_blank'])) {
                $item['target_blank'] = true;
            }

            if ($depth < 1) {
                $children = self::normalizeItems($row['children'] ?? null, depth: $depth + 1);
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
        return self::optionsForType('page');
    }

    /** @return list<array{value: string, label: string}> */
    public static function categoryOptions(): array
    {
        return self::optionsForType('category');
    }

    /**
     * Published records of one page type (page or category), as picker
     * options. Articles are intentionally excluded from menu targets.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function optionsForType(string $type): array
    {
        $default = app(Languages::class)->defaultLocale();
        $options = [];

        $pages = Page::query()
            ->where('type', $type)
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
