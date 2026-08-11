<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * Verification providers (Google Search Console, Bing Webmaster, Yandex)
 * hand out a ready-to-paste `<meta ...>` tag rather than the bare content
 * value, and admins tend to paste the whole tag. Extracts just the `content`
 * attribute when a full tag was pasted; a plain value passes through
 * unchanged.
 */
final class VerificationCode
{
    public static function extract(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '' || ! str_starts_with($trimmed, '<')) {
            return $trimmed;
        }

        if (preg_match('/content=["\']([^"\']*)["\']/i', $trimmed, $matches) === 1) {
            return trim($matches[1]);
        }

        return $trimmed;
    }
}
