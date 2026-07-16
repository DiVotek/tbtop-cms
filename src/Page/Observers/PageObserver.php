<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use DomainException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageHierarchy;
use Tbtop\Cms\Page\Support\PageSlugWriter;

class PageObserver
{
    public function __construct(
        private readonly PageTypeRegistry $registry,
        private readonly PageSlugWriter $slugs,
    ) {}

    public function saved(Page $page): void
    {
        $overrides = $page->pendingSlugOverrides;
        $page->pendingSlugOverrides = [];

        $this->slugs->sync($page, $overrides);
    }

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
            throw new DomainException(__('tbtop-cms::pages.validation.delete_system'));
        }

        $children = Page::query()->where('parent_id', $page->id);

        if ($page->isForceDeleting()) {
            $children->withTrashed();
        }

        if ($children->exists()) {
            throw new DomainException(__('tbtop-cms::pages.validation.delete_has_children'));
        }

        $this->slugs->remove($page);
    }

    /**
     * Restoring a child whose parent is still trashed would resurrect a page
     * reachable only under a path that itself 404s — block it and let the
     * admin restore the parent first. The children-guard in deleting() uses
     * the global (non-trashed) scope, so a parent can be deleted while its
     * children are already in the trash; withTrashed() is required here to
     * even see that parent.
     */
    public function restoring(Page $page): void
    {
        if ($page->parent_id === null) {
            return;
        }

        $parent = Page::withTrashed()->find($page->parent_id);

        if ($parent !== null && $parent->trashed()) {
            throw new DomainException(__('tbtop-cms::pages.validation.restore_parent_trashed'));
        }
    }

    /**
     * deleting() removed this page's slugs; recreate them via the same
     * writer used on save so the restored page is reachable on the front
     * again. No overrides: PageSlugWriter falls back to deriving the slug
     * from the page's name (slugBase()), same as any page without an
     * existing PageSlug row, and its collision-suffix logic (resolve())
     * already handles another page having taken the slug while this one
     * was trashed.
     */
    public function restored(Page $page): void
    {
        $this->slugs->sync($page);
    }

    private function guardSystemStatus(Page $page): void
    {
        if ($page->is_system && $page->isDirty('status')) {
            throw new DomainException(__('tbtop-cms::pages.validation.status_change_system'));
        }
    }
}
