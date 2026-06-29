<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use DomainException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageHierarchy;

class PageObserver
{
    public function __construct(private readonly PageTypeRegistry $registry) {}

    public function creating(Page $page): void
    {
        PageHierarchy::assert($page, $this->registry);
    }

    public function updating(Page $page): void
    {
        $this->guardSystemStatus($page);

        if ($page->isDirty(['type', 'parent_id'])) {
            PageHierarchy::assert($page, $this->registry);
        }
    }

    public function deleting(Page $page): void
    {
        if ($page->is_system) {
            throw new DomainException("Cannot delete system page [{$page->id}].");
        }

        if ($page->children()->exists()) {
            throw new DomainException("Cannot delete page [{$page->id}]: it has children.");
        }
    }

    private function guardSystemStatus(Page $page): void
    {
        if ($page->is_system && $page->isDirty('status')) {
            throw new DomainException("Cannot change status of system page [{$page->id}].");
        }
    }
}
