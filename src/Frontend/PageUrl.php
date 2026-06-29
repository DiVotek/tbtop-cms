<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Tbtop\Cms\Language\Languages;

class PageUrl
{
    public function __construct(private readonly Languages $languages) {}

    public function build(string $path, string $locale): string
    {
        $path = trim($path, '/');
        $default = $this->languages->defaultLocale();

        if ($locale !== '' && $locale !== $default) {
            return '/' . $locale . ($path === '' ? '' : '/' . $path);
        }

        return '/' . $path;
    }
}
