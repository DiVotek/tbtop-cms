<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * A sameAs profile link (social media, Wikipedia, …). Entries are typed into
 * a free-form tags field, so both the settings save path and the JSON-LD
 * emit path validate through here — only absolute http(s) URLs may be stored
 * or published as structured data.
 */
class ProfileUrl
{
    public static function valid(string $url): bool
    {
        return preg_match('#^https?://#i', $url) === 1
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
