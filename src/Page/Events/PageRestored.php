<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised after a soft-deleted page is restored. A typical subscriber
 * reinstates its cache entry or search index listing.
 */
final class PageRestored
{
    public function __construct(
        public readonly Page $page,
    ) {}
}
