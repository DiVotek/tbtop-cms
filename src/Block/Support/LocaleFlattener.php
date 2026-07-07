<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Support;

class LocaleFlattener
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $locales
     * @param  list<string>|null  $translatableFields  Dotted field paths the schema declares
     *                                                 translatable. Null keeps the legacy behavior.
     * @return array<string, mixed>
     */
    public static function flattenData(
        array $data,
        string $locale,
        string $fallback,
        array $locales,
        ?array $translatableFields = null,
    ): array {
        $out = self::flatten($data, $locale, $fallback, $locales, $translatableFields, '', $translatableFields === null);

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
        string $path,
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
                    $path,
                    $fieldIsTranslatable,
                ),
                $data,
            );
        }

        $out = [];
        foreach ($data as $key => $value) {
            $childPath = $path === '' ? (string) $key : "{$path}.{$key}";
            $out[$key] = self::flatten(
                $value,
                $locale,
                $fallback,
                $locales,
                $translatableFields,
                $childPath,
                $translatableFields !== null && in_array($childPath, $translatableFields, true),
            );
        }

        return $out;
    }

    /**
     * Orphaned locale keys are tolerated only once the schema confirms the field is
     * translatable; otherwise every key must be a currently known locale.
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
