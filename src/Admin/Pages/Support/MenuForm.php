<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Fields\Repeater;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Menu\Events\MenuSaved;
use Tbtop\Cms\Menu\Models\Menu;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * Fields + JSON round-trip for the site-menu editor. Items are a two-level
 * tree — each item is one of three types (page ref / raw url / on-page
 * anchor); a `page` item stores `page_id` so links stay rename-safe. The
 * child repeater is capped at one level: sub-items cannot nest further.
 * label/url/anchor are per-locale maps; page/category labels store only
 * overrides and fall back to the page-name translation.
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
     * label/url/anchor are per-locale maps. A label is only required for
     * url/anchor items — page/category items fall back to the page name.
     *
     * @return list<Field>
     */
    private static function itemFields(S $s, string $typePath): array
    {
        return [
            $s->text('label')->label(__('tbtop-cms::menus.fields.label'))->translatable()
                ->rules("required_if:{$typePath},url,anchor")
                ->helperText(__('tbtop-cms::menus.fields.label_hint')),
            $s->select('type')->label(__('tbtop-cms::menus.fields.type'))->required()
                ->options([
                    ['value' => 'page', 'label' => __('tbtop-cms::menus.types.page')],
                    ['value' => 'category', 'label' => __('tbtop-cms::menus.types.category')],
                    ['value' => 'url', 'label' => __('tbtop-cms::menus.types.url')],
                    ['value' => 'anchor', 'label' => __('tbtop-cms::menus.types.anchor')],
                ]),
            $s->relation('page_id')->label(__('tbtop-cms::menus.fields.page'))
                ->query(static fn (): Builder => self::queryForType('page'))
                ->labelKey('name')->searchable()
                ->rules("required_if:{$typePath},page")
                ->hiddenIf('type', '!=', 'page'),
            $s->relation('category_id')->label(__('tbtop-cms::menus.fields.category'))
                ->query(static fn (): Builder => self::queryForType('category'))
                ->labelKey('name')->searchable()
                ->rules("required_if:{$typePath},category")
                ->hiddenIf('type', '!=', 'category'),
            $s->text('url')->label(__('tbtop-cms::menus.fields.url'))->translatable()
                ->rules("required_if:{$typePath},url")
                ->hiddenIf('type', '!=', 'url'),
            $s->text('anchor')->label(__('tbtop-cms::menus.fields.anchor'))->translatable()
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
        $wasCreated = $id === null;
        $items = $form['items'] ?? null;
        // Pages referenced before this save widen the echo diff: a row
        // repointed to another page still carries the old page's prefilled
        // label, which must not be frozen as an override (see
        // withoutPageNameEchoes).
        $data = [
            'name' => (string) ($form['name'] ?? ''),
            'items' => self::normalizeItems(
                $items,
                depth: 0,
                pages: self::referencedPages($items),
                previousPages: self::referencedPages($menu->items ?? []),
            ),
        ];

        // The key is set once, at creation, and is immutable afterwards — it is
        // the stable reference other things resolve the menu by. On edit it is
        // shown read-only, so never trust an incoming value.
        if ($id === null) {
            $data['key'] = (string) ($form['key'] ?? '');
        }

        $menu->fill($data);
        $menu->save();

        event(new MenuSaved($menu, $wasCreated));

        return $menu;
    }

    /** @return array<string, mixed> */
    public static function loadRow(int $id): array
    {
        $menu = Menu::query()->findOrFail($id);
        $items = $menu->items ?? [];

        return [
            'name' => $menu->name,
            'key' => $menu->key,
            'items' => self::itemsForForm($items, self::referencedPages($items)),
        ];
    }

    /**
     * Stored items -> form rows: label/url/anchor become locale maps (legacy
     * scalars belong to the default locale), and page/category labels are
     * prefilled per locale with the page-name translation wherever no override
     * is stored — save() diffs the same values back out, so an untouched
     * prefill keeps the fallback live instead of freezing a copy.
     *
     * @param  list<mixed>  $items
     * @param  array<int, Page>  $pages
     * @return list<array<string, mixed>>
     */
    private static function itemsForForm(array $items, array $pages): array
    {
        $locales = array_column(app(Languages::class)->adminActive(), 'locale');
        $rows = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $item['label'] = self::localeMap($item['label'] ?? null);

            foreach (['url', 'anchor'] as $field) {
                if (array_key_exists($field, $item)) {
                    $item[$field] = self::localeMap($item[$field]);
                }
            }

            $page = $pages[(int) ($item['page_id'] ?? $item['category_id'] ?? 0)] ?? null;
            if ($page instanceof Page) {
                foreach ($locales as $locale) {
                    if (($item['label'][$locale] ?? '') !== '') {
                        continue;
                    }
                    $name = trim((string) $page->getTranslation('name', $locale, false));
                    if ($name !== '') {
                        $item['label'][$locale] = $name;
                    }
                }
            }

            if (isset($item['children']) && is_array($item['children'])) {
                $item['children'] = self::itemsForForm($item['children'], $pages);
            }

            $rows[] = $item;
        }

        return $rows;
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
     * label/url/anchor persist as locale maps of non-empty overrides. For
     * url/anchor the default locale is the fallback source, so a row without
     * a default-locale label or target is dropped. For page/category a label
     * locale equal to the page-name translation is not stored — the fallback
     * stays live, so renaming the page renames untouched menu items.
     *
     * @param  array<int, Page>  $pages
     * @param  array<int, Page>  $previousPages
     * @return list<array<string, mixed>>
     */
    private static function normalizeItems(mixed $rows, int $depth, array $pages, array $previousPages): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $default = app(Languages::class)->defaultLocale();
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $type = in_array($row['type'] ?? null, ['page', 'category', 'url', 'anchor'], true)
                ? $row['type']
                : 'page';

            $targetField = self::TARGET_FIELDS[$type];
            $label = self::localeMap($row['label'] ?? null);
            $item = ['type' => $type];

            if (in_array($type, ['page', 'category'], true)) {
                $target = (int) ($row[$targetField] ?? 0);
                if ($target <= 0) {
                    continue;
                }
                $label = self::withoutPageNameEchoes($label, $pages[$target] ?? null, $previousPages);
                if ($label !== []) {
                    $item['label'] = $label;
                }
                $item[$targetField] = $target;
            } else {
                $target = self::localeMap($row[$targetField] ?? null);
                if (($target[$default] ?? '') === '' || ($label[$default] ?? '') === '') {
                    continue;
                }
                $item['label'] = $label;
                $item[$targetField] = $target;
            }

            if (! empty($row['target_blank'])) {
                $item['target_blank'] = true;
            }

            if ($depth < 1) {
                $children = self::normalizeItems(
                    $row['children'] ?? null,
                    depth: $depth + 1,
                    pages: $pages,
                    previousPages: $previousPages,
                );
                if ($children !== []) {
                    $item['children'] = $children;
                }
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * A locale whose label matches the page-name translation is an untouched
     * prefill (see itemsForForm), not an override — storing it would freeze
     * the copy and break the live fallback. Previously referenced pages are
     * checked too: a row repointed to another page still submits the old
     * page's prefilled label, which would otherwise persist as a stale
     * override showing the old page's name on the site.
     *
     * @param  array<string, string>  $label
     * @param  array<int, Page>  $previousPages
     * @return array<string, string>
     */
    private static function withoutPageNameEchoes(array $label, ?Page $page, array $previousPages): array
    {
        $sources = $page instanceof Page ? [$page, ...$previousPages] : $previousPages;

        foreach ($label as $locale => $text) {
            foreach ($sources as $source) {
                if ($text === trim((string) $source->getTranslation('name', $locale, false))) {
                    unset($label[$locale]);

                    break;
                }
            }
        }

        return $label;
    }

    /**
     * Trimmed non-empty values per locale. A legacy scalar (stored before
     * menus became translatable) counts as the default locale's value.
     *
     * @return array<string, string>
     */
    private static function localeMap(mixed $value): array
    {
        if (is_string($value)) {
            $value = [app(Languages::class)->defaultLocale() => $value];
        }
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $locale => $text) {
            if (! is_string($locale) || ! is_string($text)) {
                continue;
            }
            $text = trim($text);
            if ($text !== '') {
                $map[$locale] = $text;
            }
        }

        return $map;
    }

    /**
     * Pages referenced by page/category items at either level, keyed by id —
     * one query backing both the label prefill and the override diff.
     *
     * @return array<int, Page>
     */
    private static function referencedPages(mixed $rows): array
    {
        $ids = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach (['page_id', 'category_id'] as $field) {
                $id = (int) ($row[$field] ?? 0);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            foreach (is_array($row['children'] ?? null) ? $row['children'] : [] as $child) {
                foreach (['page_id', 'category_id'] as $field) {
                    $id = (int) (is_array($child) ? ($child[$field] ?? 0) : 0);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        if ($ids === []) {
            return [];
        }

        return Page::query()->whereIn('id', array_unique($ids))->get()->keyBy(
            static fn (Page $page): int => (int) $page->getKey(),
        )->all();
    }

    /**
     * Retained for callers that need the whole options list at once (e.g.
     * tests asserting the picker's candidate set) — the page_id relation
     * field itself now resolves options one page at a time via queryForType().
     *
     * @return list<array{value: string, label: string}>
     */
    public static function pageOptions(): array
    {
        return self::optionsForType('page');
    }

    /** @return list<array{value: string, label: string}> */
    public static function categoryOptions(): array
    {
        return self::optionsForType('category');
    }

    /** @return list<array{value: string, label: string}> */
    private static function optionsForType(string $type): array
    {
        $default = app(Languages::class)->defaultLocale();
        $options = [];

        foreach (self::queryForType($type)->get() as $page) {
            $name = $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey());
            $options[] = ['value' => (string) $page->getKey(), 'label' => $name];
        }

        return $options;
    }

    /**
     * Published records of one page type (page or category); backs both the
     * relation picker's query() and the legacy options-array helpers above.
     * Articles are intentionally excluded from menu targets.
     *
     * Filtered by status only, not the live scope: an editor may deliberately
     * pick a page that is scheduled for the future (or past its unpublish
     * date) to link it in advance — MenuResolver applies the live scope at
     * render time and drops the item from the frontend menu until/while the
     * page is actually live.
     *
     * @return Builder<Page>
     */
    private static function queryForType(string $type): Builder
    {
        return Page::query()
            ->where('type', $type)
            ->where('status', PageStatus::Published->value)
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
