<?php

declare(strict_types=1);

namespace Tbtop\Cms\Redirect\Support;

class RedirectPath
{
    public static function isExternal(string $target): bool
    {
        // Browsers fold "\" into "/" when parsing URLs, so "\\evil.example"
        // and "/\evil.example" resolve exactly like the protocol-relative
        // "//evil.example" — classify through the browser's eyes, not the
        // literal string.
        $target = str_replace('\\', '/', $target);

        return str_starts_with($target, 'http://') || str_starts_with($target, 'https://') || str_starts_with($target, '//');
    }

    public static function normalize(string $path): string
    {
        // Backslashes are trimmed alongside slashes: a stored "\evil.example"
        // would otherwise render as the "/\evil.example" Location that
        // browsers fold into the external "//evil.example".
        return trim($path, '/\\');
    }
}
