<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin;

use Tbtop\Cms\Language\Languages;

class ContentLocales
{
    public static function sync(Languages $languages): void
    {
        $locales = array_column($languages->adminActive(), 'locale');

        if ($locales === []) {
            return;
        }

        $default = $languages->defaultLocale();

        config([
            'tbtop-admin.content_locales' => $locales,
            'tbtop-admin.default_content_locale' => $default !== '' ? $default : $locales[0],
        ]);
    }
}
