<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend;

use Illuminate\Support\Facades\URL;
use Tbtop\Cms\Page\Models\Page;

/**
 * Builds a shareable, time-limited signed URL for a draft page — hits the
 * same named catch-all route (`tbtop-cms.page`) as a normal page view, but
 * with a signature the frontend guard accepts in place of an admin session.
 */
class PreviewLink
{
    public function __construct(private readonly PageUrl $pageUrl) {}

    public function for(Page $page, string $locale): string
    {
        $ttlHours = (int) config('tbtop-cms.preview.link_ttl_hours', 72);

        // PageUrl owns the locale-prefix rule (non-default locales live under
        // /{locale}/...); without it a non-default-locale link would point at
        // the default-locale path and 404. ltrim: the route param is relative.
        $path = ltrim($this->pageUrl->build((string) $page->pathFor($locale), $locale), '/');

        return URL::temporarySignedRoute(
            'tbtop-cms.page',
            now()->addHours($ttlHours),
            ['path' => $path],
        );
    }
}
