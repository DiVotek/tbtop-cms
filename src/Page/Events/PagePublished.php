<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised when a page's status transitions into Published — on create or on
 * update. A typical subscriber notifies subscribers or triggers a sitemap
 * refresh.
 */
final class PagePublished
{
    public function __construct(
        public readonly Page $page,
    ) {}
}
