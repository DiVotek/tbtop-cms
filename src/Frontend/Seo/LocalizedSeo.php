<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * Reads a translatable value out of a `seo`/settings JSON blob for a given
 * locale. Translatable fields are stored as locale maps
 * (`{"en": "...", "uk": "..."}`), but rows written before the field became
 * translatable — or settings saved before this feature existed — carry a
 * plain scalar. That legacy shape was de-facto shared across every locale
 * (there was no per-locale concept yet), so it is served as-is for ANY
 * locale rather than only the default one; this also covers old
 * PageRevisionWriter snapshots, which freeze whatever shape existed at
 * snapshot time.
 */
final class LocalizedSeo
{
    public static function value(mixed $raw, string $locale): string
    {
        if (is_string($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            $value = $raw[$locale] ?? null;

            return is_string($value) ? $value : '';
        }

        return '';
    }
}
