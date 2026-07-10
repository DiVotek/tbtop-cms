<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageFormOptions
{
    /**
     * Sentinel for "no parent". Radix Select forbids an empty-string item
     * value (it crashes the whole page), so the option carries this marker
     * and PageForm maps it back to null on save.
     */
    public const NO_PARENT = 'none';

    /** @return list<array{value: string, label: string}> */
    public static function types(): array
    {
        $options = [];

        foreach (app(PageTypeRegistry::class)->all() as $type) {
            $options[] = ['value' => $type->key(), 'label' => self::typeLabel($type->key(), $type->label())];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(
            static fn (PageStatus $status): array => [
                'value' => $status->value,
                'label' => __('tbtop-cms::pages.status.' . $status->value),
            ],
            PageStatus::cases(),
        );
    }

    /**
     * Only categories are valid parents — categories are the sole grouping
     * container in the page hierarchy (see PageTypeRegistry/CategoryType).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function parents(): array
    {
        $default = app(Languages::class)->defaultLocale();

        $options = [['value' => self::NO_PARENT, 'label' => __('tbtop-cms::pages.fields.no_parent')]];

        foreach (Page::query()->where('type', 'category')->orderBy('sort_order')->orderBy('id')->get() as $page) {
            $name = $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey());
            $options[] = ['value' => (string) $page->getKey(), 'label' => $name];
        }

        return $options;
    }

    /**
     * Registry labels aren't guaranteed to be translated; fall back through
     * pages.types.* (already used for the list's type column) before the
     * raw registry label.
     */
    private static function typeLabel(string $key, string $fallback): string
    {
        $translationKey = 'tbtop-cms::pages.types.' . $key;
        $label = __($translationKey);

        return $label === $translationKey ? $fallback : $label;
    }
}
