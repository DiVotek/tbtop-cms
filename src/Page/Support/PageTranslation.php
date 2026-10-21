<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Support\BlankValue;

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

    /** A text field (name, title, heading, summary) by the same rule: null when blank in both locales. */
    public static function text(Page $page, string $field, string $locale, string $fallbackLocale): ?string
    {
        $value = self::get($page, $field, $locale, $fallbackLocale);

        return is_string($value) ? $value : null;
    }

    /**
     * The first of $fields with text, each tried in the active locale before
     * any in the fallback one — `['title', 'name']` prefers this locale's name
     * over the default locale's title. '' when every one is blank.
     *
     * @param  list<string>  $fields
     */
    public static function firstText(Page $page, array $fields, string $locale, string $fallbackLocale): string
    {
        foreach (array_unique([$locale, $fallbackLocale]) as $candidate) {
            foreach ($fields as $field) {
                $value = $page->getTranslation($field, $candidate, false);
                if (is_string($value) && ! self::isBlank($value)) {
                    return $value;
                }
            }
        }

        return '';
    }

    private static function isBlank(mixed $value): bool
    {
        return BlankValue::is($value);
    }
}
