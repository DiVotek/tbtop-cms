<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Events;

use Tbtop\Cms\Page\Models\Page;

/**
 * Raised when a page's status transitions out of Published on update. A
 * typical subscriber removes the page from a public listing or search index.
 */
final class PageUnpublished
{
    public function __construct(
        public readonly Page $page,
    ) {}
}
