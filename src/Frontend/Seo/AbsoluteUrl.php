<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo;

/**
 * Absolutises a stored media URL for consumers that read it away from the page.
 *
 * UploadUrl deliberately returns same-origin URLs as root-relative paths, so
 * stored links survive a host or port change. That is right for markup a
 * browser resolves against the current page, and wrong for anything a machine
 * reads on its own: og:image is fetched by a share crawler with no page
 * context, JSON-LD is consumed as data, and a sitemap is parsed from a file
 * that carries no origin. All three need the full address.
 *
 * Not applied to the favicon: it only ever appears in a <link> the browser
 * resolves relative to the document it is already showing.
 */
final class AbsoluteUrl
{
    /** Leaves an already-absolute URL (an external disk, e.g. s3) untouched. */
    public static function make(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://')
            ? $url
            : url($url);
    }
}
