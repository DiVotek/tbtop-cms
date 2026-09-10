<?php

declare(strict_types=1);

namespace Tbtop\Cms\Link;

use Illuminate\Support\Facades\Validator;
use Tbtop\Admin\I18n\LocaleService;
use Tbtop\Cms\Page\Models\Page;

/**
 * Named rule `link_shape:<allowed types>`. A named validator, not a Rule
 * object: Field collects rules as `list<string>` and PrefixedField replays them.
 */
final class LinkShapeValidator
{
    public const RULE = 'link_shape';

    /** 6-20 digits, optional leading `+`, with spaces/dashes/parentheses allowed between them. */
    private const PHONE_PATTERN = '/^\+?[0-9 ()\-]{5,40}$/';

    /** Bare fragment, no leading `#`. Mirrored in resources/js/cms/admin/link/validate.ts. */
    private const ANCHOR_PATTERN = '/^[A-Za-z0-9_-]+$/';

    private const LABEL_MAX = 255;

    public static function register(): void
    {
        Validator::extend(
            self::RULE,
            static fn (string $attribute, mixed $value, array $parameters): bool => self::passes($value, $parameters),
        );

        // Resolved per-validator rather than passed to extend(): the message
        // argument there is a plain string, so it would freeze on the boot locale.
        Validator::replacer(
            self::RULE,
            static fn (): string => (string) __('tbtop-cms::link.invalid'),
        );
    }

    /** @param list<string> $allowedTypes */
    public static function passes(mixed $value, array $allowedTypes = []): bool
    {
        // `nullable` already let a null through; an empty array is what an
        // untouched field posts back, and neither is this rule's business.
        if ($value === null || $value === []) {
            return true;
        }

        $link = LinkValue::parse($value);

        if ($link === null) {
            return false;
        }

        if ($allowedTypes !== [] && ! in_array($link->type->value, $allowedTypes, true)) {
            return false;
        }

        if (! self::labelIsValid($value['label'] ?? null)) {
            return false;
        }

        return match ($link->type) {
            LinkType::Page => self::pageIsValid($link),
            LinkType::External => $link->value !== null && self::externalIsValid($link->value),
            LinkType::Email => $link->value !== null && filter_var($link->value, FILTER_VALIDATE_EMAIL) !== false,
            LinkType::Phone => $link->value !== null && self::phoneIsValid($link->value),
        };
    }

    /** Read raw, not off LinkValue: the max-length and known-locale gates are about what was posted. */
    private static function labelIsValid(mixed $label): bool
    {
        if ($label === null) {
            return true;
        }

        // Already collapsed to one string by LocaleFlattener when a resolved
        // value is revalidated; a scalar carries no locale keys to check.
        if (is_string($label)) {
            return mb_strlen($label) <= self::LABEL_MAX;
        }

        if (! is_array($label)) {
            return false;
        }

        $locales = LocaleService::contentLocales();

        foreach ($label as $locale => $text) {
            if (! is_string($locale) || ! in_array($locale, $locales, true)) {
                return false;
            }

            if ($text !== null && (! is_string($text) || mb_strlen($text) > self::LABEL_MAX)) {
                return false;
            }
        }

        return true;
    }

    /** http(s) URL, or a messenger deep link on the ExternalSchemes allowlist. */
    private static function externalIsValid(string $value): bool
    {
        $isHttpUrl = filter_var($value, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['http', 'https'], true);

        return $isHttpUrl || ExternalSchemes::matches($value);
    }

    private static function pageIsValid(LinkValue $link): bool
    {
        if ($link->page === null || ! ctype_digit($link->page)) {
            return false;
        }

        if ($link->anchor !== null && preg_match(self::ANCHOR_PATTERN, $link->anchor) !== 1) {
            return false;
        }

        return Page::query()->whereKey($link->page)->exists();
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
