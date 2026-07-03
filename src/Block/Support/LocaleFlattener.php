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

        if (self::isLocaleMap($data, $locale, $fallback, $locales)) {
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
     * A key no longer in $locales (its language was deleted) doesn't disqualify the
     * map, as long as $locale or $fallback — the keys actually read below — is present
     * and every other key still looks like a locale code rather than an unrelated field.
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

        if (array_all($keys, fn (string $key): bool => in_array($key, $locales, true))) {
            return true;
        }

        if (! in_array($locale, $keys, true) && ! in_array($fallback, $keys, true)) {
            return false;
        }

        return array_all(
            $keys,
            fn (string $key): bool => in_array($key, $locales, true) || self::looksLikeLocale($key),
        );
    }

    private static function looksLikeLocale(string $key): bool
    {
        return preg_match('/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/', $key) === 1;
    }
}
