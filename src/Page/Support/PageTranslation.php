<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Tbtop\Cms\Page\Models\Page;

/**
 * Reads a page's translatable field for the active locale, falling back to
 * the site's default locale. Unlike Page::getTranslation(), a saved-but-empty
 * translation counts as missing — the editor stores '' for every locale it
 * showed, which must not shadow the default-locale text.
 */
final class PageTranslation
{
    public static function get(Page $page, string $field, string $locale, string $fallbackLocale): mixed
    {
        foreach (array_unique([$locale, $fallbackLocale]) as $candidate) {
            $value = $page->getTranslation($field, $candidate, false);
            if (! self::isBlank($value)) {
                return $value;
            }
        }

        return null;
    }

    private static function isBlank(mixed $value): bool
    {
        if (is_array($value)) {
            // A richtext field saves the editor's empty document, not null.
            return $value === [] || ($value['root']['children'] ?? null) === [];
        }

        return $value === null || (is_string($value) && trim($value) === '');
    }
}
