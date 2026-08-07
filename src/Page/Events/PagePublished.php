<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised when a page's status transitions into Published — on create or on
 * update. A typical subscriber notifies subscribers or triggers a sitemap
 * refresh.
 *
 * This fires at save time, on the status transition — not when the page
 * actually becomes visible. With scheduling (published_at/unpublish_at),
 * frontend visibility (see Page::isLive()/scopeLive()) may start later than
 * this event, or may not have started/may have already ended at all if the
 * schedule places it outside "now". Time-based cache invalidation for a
 * scheduled go-live/go-dark is not wired to this event; it arrives with the
 * caching work.
 */
final class PagePublished
{
    public function __construct(
        public readonly Page $page,
    ) {}
}
