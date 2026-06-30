<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Language\Languages;

class RedirectFormOptions
{
    /** @return list<array{value: string, label: string}> */
    public static function locales(): array
    {
        $options = [['value' => '', 'label' => 'All locales']];

        foreach (app(Languages::class)->frontendActive() as $row) {
            $options[] = ['value' => $row['locale'], 'label' => $row['name']];
        }

        return $options;
    }

    /** @return list<array{value: string, label: string}> */
    public static function statuses(): array
    {
        return [
            ['value' => '301', 'label' => '301 Permanent'],
            ['value' => '302', 'label' => '302 Temporary'],
        ];
    }
}
