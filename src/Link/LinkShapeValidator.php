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

        if (! LinkTarget::isWellFormed($link)) {
            return false;
        }

        return $link->type !== LinkType::Page || Page::query()->whereKey($link->page)->exists();
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
}
