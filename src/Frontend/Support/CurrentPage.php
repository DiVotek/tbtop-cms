<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Support;

use Tbtop\Cms\Page\Models\Page;

/**
 * Request-scoped: the page rendering this request, set by PageController
 * and by ErrorResponse. Layout slots aren't page-bound, so ShareCmsProps
 * reads this to bind their form blocks to whichever page is on screen.
 */
final class CurrentPage
{
    private ?Page $page = null;

    public function set(Page $page): void
    {
        $this->page = $page;
    }

    public function get(): ?Page
    {
        return $this->page;
    }
}
