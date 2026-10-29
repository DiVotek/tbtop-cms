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

    /** @var array{page: int, pages: int}|null */
    private ?array $pagination = null;

    public function set(Page $page): void
    {
        $this->page = $page;
    }

    public function get(): ?Page
    {
        return $this->page;
    }

    /** Recorded by PagedPages: which page of how many the rendered archive shows. */
    public function paginate(int $page, int $pages): void
    {
        $this->pagination = ['page' => $page, 'pages' => $pages];
    }

    /** @return array{page: int, pages: int}|null Null when the page lists nothing paged. */
    public function pagination(): ?array
    {
        return $this->pagination;
    }
}
