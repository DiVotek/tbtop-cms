<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block\Support;

class LocaleFlattener
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $locales
     * @return array<string, mixed>
     */
    public static function flattenData(array $data, string $locale, string $fallback, array $locales): array
    {
        $out = self::flatten($data, $locale, $fallback, $locales);

        return is_array($out) ? $out : [];
    }

    /** @param list<string> $locales */
    private static function flatten(mixed $data, string $locale, string $fallback, array $locales): mixed
    {
        if (! is_array($data) || $data === []) {
            return $data;
        }

        if (self::isLocaleMap($data, $locales)) {
            return $data[$locale] ?? $data[$fallback] ?? null;
        }

        if (array_is_list($data)) {
            return array_map(fn (mixed $item): mixed => self::flatten($item, $locale, $fallback, $locales), $data);
        }

        $out = [];
        foreach ($data as $key => $value) {
            $out[$key] = self::flatten($value, $locale, $fallback, $locales);
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $locales
     */
    private static function isLocaleMap(array $data, array $locales): bool
    {
        if (array_is_list($data)) {
            return false;
        }

        return array_all(array_keys($data), fn ($key): bool => in_array((string) $key, $locales, true));
    }
}
