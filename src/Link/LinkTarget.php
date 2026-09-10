<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

/** Shared by save-time validation and render-time resolution; page existence and schedule stay with the callers. */
final class LinkTarget
{
    /** 6-20 digits, optional leading `+`, with spaces/dashes/parentheses allowed between them. */
    private const PHONE_PATTERN = '/^\+?[0-9 ()\-]{5,40}$/';

    /** Bare fragment, no leading `#`. Mirrored in resources/js/cms/admin/link/validate.ts. */
    private const ANCHOR_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public static function isWellFormed(LinkValue $link): bool
    {
        return match ($link->type) {
            LinkType::Page => self::pageIsWellFormed($link),
            LinkType::External => $link->value !== null && self::externalIsValid($link->value),
            LinkType::Email => $link->value !== null && filter_var($link->value, FILTER_VALIDATE_EMAIL) !== false,
            LinkType::Phone => $link->value !== null && self::phoneIsValid($link->value),
        };
    }

    private static function pageIsWellFormed(LinkValue $link): bool
    {
        if ($link->page === null || ! ctype_digit($link->page)) {
            return false;
        }

        return $link->anchor === null || preg_match(self::ANCHOR_PATTERN, $link->anchor) === 1;
    }

    /** http(s) URL, or a messenger deep link on the ExternalSchemes allowlist. */
    private static function externalIsValid(string $value): bool
    {
        $isHttpUrl = filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);

        return $isHttpUrl || ExternalSchemes::matches($value);
    }

    private static function phoneIsValid(string $value): bool
    {
        if (preg_match(self::PHONE_PATTERN, $value) !== 1) {
            return false;
        }

        $digits = strlen(preg_replace('/[^0-9]/', '', $value) ?? '');

        return $digits >= 6 && $digits <= 20;
    }
}
