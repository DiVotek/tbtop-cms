<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Support;

class LocaleFlattener
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $locales
     * @param  list<string>|null  $translatableFields  Field names the block schema declares
     *                                                 translatable. Null preserves the old
     *                                                 shape-guessing behavior for callers that
     *                                                 can't supply a schema.
     * @return array<string, mixed>
     */
    public static function flattenData(
        array $data,
        string $locale,
        string $fallback,
        array $locales,
        ?array $translatableFields = null,
    ): array {
        $out = self::flatten($data, $locale, $fallback, $locales, $translatableFields, true);

        return is_array($out) ? $out : [];
    }

    /**
     * @param  list<string>  $locales
     * @param  list<string>|null  $translatableFields
     */
    private static function flatten(
        mixed $data,
        string $locale,
        string $fallback,
        array $locales,
        ?array $translatableFields,
        bool $fieldIsTranslatable,
    ): mixed {
        if (! is_array($data) || $data === []) {
            return $data;
        }

        if ($fieldIsTranslatable && self::isLocaleMap($data, $locale, $fallback, $locales)) {
            return $data[$locale] ?? $data[$fallback] ?? null;
        }

        if (array_is_list($data)) {
            return array_map(
                fn (mixed $item): mixed => self::flatten(
                    $item,
                    $locale,
                    $fallback,
                    $locales,
                    $translatableFields,
                    $fieldIsTranslatable,
                ),
                $data,
            );
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::flatten(
                $value,
                $locale,
                $fallback,
                $locales,
                $translatableFields,
                self::isTranslatable((string) $key, $translatableFields),
            );
        }

        return $out;
    }

    /** @param list<string>|null $translatableFields */
    private static function isTranslatable(string $key, ?array $translatableFields): bool
    {
        return $translatableFields === null || in_array($key, $translatableFields, true);
    }

    /**
     * A key no longer in $locales (its language was deleted) doesn't disqualify the map, as
     * long as $locale or $fallback — the keys actually read below — is present. This is only
     * reached for fields the schema already confirmed are translatable, so an unrelated key
     * shaped like a locale code can no longer be misread as one.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $locales
     */
    private static function isLocaleMap(array $data, string $locale, string $fallback, array $locales): bool
    {
        if (array_is_list($data)) {
            return false;
        }

        $keys = array_map(static fn (int | string $key): string => (string) $key, array_keys($data));

        return in_array($locale, $keys, true) || in_array($fallback, $keys, true)
            || array_all($keys, fn (string $key): bool => in_array($key, $locales, true));
    }
}
