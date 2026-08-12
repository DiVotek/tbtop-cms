<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Support;

/**
 * Scanner-noise filter for the 404 log — a path matching any of these is
 * near-certainly an automated probe (PHP/WordPress/env-file scanners, not a
 * broken link a real visitor followed), so it is worth dropping before it
 * ever reaches the counter rather than letting bots inflate it.
 */
final class NotFoundNoise
{
    /** @var list<string> Case-insensitive substring/prefix fragments, not regex. */
    public const DEFAULT_IGNORE = [
        '.php', '.env', 'wp-', '.git', '.well-known', 'xmlrpc', '.aspx', '.cgi',
    ];

    /** Paths longer than this are always garbage — never a real route. */
    public const MAX_LENGTH = 2048;

    /** @param  list<string>  $patterns */
    public static function shouldIgnore(string $path, array $patterns): bool
    {
        if ($path === '' || mb_strlen($path) > self::MAX_LENGTH) {
            return true;
        }

        $haystack = mb_strtolower($path);

        foreach ($patterns as $pattern) {
            if ($pattern !== '' && str_contains($haystack, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
