<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised when a page's status transitions out of Published on update. A
 * typical subscriber removes the page from a public listing or search index.
 *
 * This fires at save time, on the status transition — not when the page
 * actually stops being visible. With scheduling (published_at/unpublish_at),
 * a page can also stop being live purely by its unpublish_at passing, with
 * status left at Published and no PageUnpublished raised at all — see
 * Page::isLive()/scopeLive(). Time-based cache invalidation for that case is
 * not wired to this event; it arrives with the caching work.
 */
final class PageUnpublished
{
    public function __construct(
        public readonly Page $page,
    ) {}
}
