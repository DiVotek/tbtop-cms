<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

use Illuminate\Support\Facades\Validator;

/**
 * Named rule `safe_href` for free-text link fields (Hero's CTA): a relative
 * URL, or an http(s), mailto, tel or ExternalSchemes deep link. Any other
 * scheme (`javascript:`, `data:`, `vbscript:`) fails, at save and at render.
 */
final class SafeHref
{
    public const RULE = 'safe_href';

    private const SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public static function register(): void
    {
        Validator::extend(
            self::RULE,
            static fn (string $attribute, mixed $value): bool => $value === null || (is_string($value) && self::passes($value)),
        );

        Validator::replacer(
            self::RULE,
            static fn (): string => (string) __('tbtop-cms::link.invalid'),
        );
    }

    public static function passes(string $href): bool
    {
        // What a browser parses: leading C0 controls and spaces dropped, tabs
        // and newlines removed anywhere, so "java\tscript:" is javascript:.
        $parsed = preg_replace('/[\t\n\r]/', '', ltrim($href, "\x00..\x20")) ?? '';

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $parsed, $match) !== 1) {
            return true;
        }

        return in_array(strtolower($match[1]), self::SCHEMES, true) || ExternalSchemes::matches($parsed);
    }
}
