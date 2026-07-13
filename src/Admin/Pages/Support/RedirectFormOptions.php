<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Language\Languages;

class RedirectFormOptions
{
    /**
     * Sentinel for "applies to every locale" — the client Select forbids
     * empty option values, so null is encoded as this constant in forms.
     */
    public const ALL_LOCALES = 'all';

    /** @return list<array{value: string, label: string}> */
    public static function locales(): array
    {
        $options = [['value' => self::ALL_LOCALES, 'label' => __('tbtop-cms::redirects.locale_all')]];

        foreach (app(Languages::class)->frontendActive() as $row) {
            $options[] = ['value' => $row['locale'], 'label' => $row['name']];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return [
            ['value' => '301', 'label' => __('tbtop-cms::redirects.statuses.301')],
            ['value' => '302', 'label' => __('tbtop-cms::redirects.statuses.302')],
        ];
    }
}
