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
        $out = self::flatten($data, $locale, $fallback, $locales, $translatableFields, $translatableFields === null);

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

        if (self::isLocaleMap($data, $locale, $fallback, $locales, $fieldIsTranslatable)) {
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
                $translatableFields !== null && in_array((string) $key, $translatableFields, true),
            );
        }

        return $out;
    }

    /**
     * A key no longer in $locales (its language was deleted) doesn't disqualify the map, as
     * long as $locale or $fallback — the keys actually read below — is present. That relaxed
     * check only applies once the schema has confirmed the field is translatable; without a
     * schema (or for a field the schema didn't mark translatable), every key must still be a
     * currently known locale, so an unrelated field can't be misread as a locale map.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $locales
     */
    private static function isLocaleMap(
        array $data,
        string $locale,
        string $fallback,
        array $locales,
        bool $fieldIsTranslatable,
    ): bool {
        if (array_is_list($data)) {
            return false;
        }

        $keys = array_map(static fn (int | string $key): string => (string) $key, array_keys($data));

        if ($fieldIsTranslatable && (in_array($locale, $keys, true) || in_array($fallback, $keys, true))) {
            return true;
        }

        return array_all($keys, fn (string $key): bool => in_array($key, $locales, true));
    }
}
