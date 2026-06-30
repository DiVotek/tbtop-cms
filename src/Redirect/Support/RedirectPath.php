<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Support;

class RedirectPath
{
    public static function isExternal(string $target): bool
    {
        return str_starts_with($target, 'http://') || str_starts_with($target, 'https://');
    }

    public static function normalize(string $path): string
    {
        return trim($path, '/');
    }
}
