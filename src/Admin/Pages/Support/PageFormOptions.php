<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

class PageFormOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function types(): array
    {
        $options = [];

        foreach (app(PageTypeRegistry::class)->all() as $type) {
            $options[] = ['value' => $type->key(), 'label' => $type->label()];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return array_map(
            static fn (PageStatus $status): array => ['value' => $status->value, 'label' => ucfirst($status->value)],
            PageStatus::cases(),
        );
    }

    /** @return list<array{value: string, label: string}> */
    public static function parents(): array
    {
        $default = app(Languages::class)->defaultLocale();

        $options = [['value' => '', 'label' => '— None —']];

        foreach (Page::query()->orderBy('sort_order')->orderBy('id')->get() as $page) {
            $name = $page->getTranslation('name', $default, false) ?: ('#' . $page->getKey());
            $options[] = ['value' => (string) $page->getKey(), 'label' => $name];
        }

        return $options;
    }
}
