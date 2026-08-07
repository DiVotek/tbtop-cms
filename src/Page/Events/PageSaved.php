<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised whenever a page finishes saving (create or update), regardless of
 * what changed. A typical subscriber invalidates a per-page cache entry or
 * reindexes the page for search.
 */
final class PageSaved
{
    public function __construct(
        public readonly Page $page,
        public readonly bool $wasCreated,
    ) {}
}
