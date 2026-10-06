<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Refs\PageRefIndex;

/**
 * Rewrites a page's rows in the page references index on every save. A
 * separate observer from PageObserver, wired like PageRevisions: the index
 * is derived data, not one of PageObserver's structural guards. A force
 * delete needs nothing here — the page_id foreign key cascades.
 */
class PageRefs
{
    public function __construct(private readonly PageRefIndex $index) {}

    public function saved(Page $page): void
    {
        $this->index->write($page);
    }
}
