<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised after a page is deleted, soft or forced. A typical subscriber
 * invalidates its cache entry or removes it from a search index.
 */
final class PageDeleted
{
    public function __construct(
        public readonly Page $page,
        public readonly bool $forced,
    ) {}
}
