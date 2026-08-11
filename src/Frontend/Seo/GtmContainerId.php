<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * Google Tag Manager container id, extracted from whatever the admin pasted —
 * the bare "GTM-XXXXXXX" or the full two-part install snippet Google shows.
 */
class GtmContainerId
{
    /**
     * Pulls the first GTM-… token out of the raw value; anything without one
     * (including the empty string) comes back trimmed as-is so the admin sees
     * their input back instead of it silently vanishing.
     */
    public static function extract(string $raw): string
    {
        if (preg_match('/GTM-[A-Z0-9]+/i', $raw, $matches) === 1) {
            return strtoupper($matches[0]);
        }

        return trim($raw);
    }

    /**
     * Only a well-formed container id may reach the rendered snippet: the id
     * is interpolated into a script URL and an iframe URL, so everything else
     * is dropped at render time regardless of what the setting stores.
     */
    public static function valid(string $id): bool
    {
        return preg_match('/^GTM-[A-Z0-9]+$/', $id) === 1;
    }
}
