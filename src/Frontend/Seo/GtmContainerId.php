<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

use Tbtop\Cms\Settings\Settings;

/**
 * Google Tag Manager container id, extracted from whatever the admin pasted —
 * the bare "GTM-XXXXXXX" or the full two-part install snippet Google shows.
 */
class GtmContainerId
{
    /**
     * The one read path for rendering (used by the InjectGtm middleware):
     * extracts at read time too — not only on save in SeoSettingsPage — so a
     * value written programmatically or as the full pasted snippet still
     * resolves; anything that doesn't normalize to a well-formed GTM-… id is
     * dropped rather than interpolated into the markup.
     */
    public static function fromSettings(Settings $settings): ?string
    {
        $raw = $settings->get('seo.gtm_container_id', '');

        if (! is_string($raw)) {
            return null;
        }

        $id = self::extract($raw);

        return self::valid($id) ? $id : null;
    }

    /**
     * Accepts exactly two shapes: a bare container id (anchored match), or
     * Google's install snippet — recognized by its googletagmanager.com
     * reference — from which the first GTM-… token is mined. Anything else
     * (including arbitrary text that merely contains a GTM-looking
     * substring, e.g. "not-a-container-GTM-ABC123") comes back trimmed
     * as-is: the admin sees their input back instead of it silently
     * vanishing, and valid() rejects it so no snippet is rendered.
     */
    public static function extract(string $raw): string
    {
        $trimmed = trim($raw);

        if (preg_match('/^GTM-[A-Z0-9]+$/i', $trimmed) === 1) {
            return strtoupper($trimmed);
        }

        if (stripos($trimmed, 'googletagmanager.com') !== false
            && preg_match('/GTM-[A-Z0-9]+/i', $trimmed, $matches) === 1) {
            return strtoupper($matches[0]);
        }

        return $trimmed;
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
