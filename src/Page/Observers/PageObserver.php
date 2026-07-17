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

    /**
     * Bumps lock_version on every real update, at the model layer rather than
     * only inside PageForm::save() — PageRevisionWriter::restore() also calls
     * Page::save() directly (not through PageForm), and a restore must
     * invalidate any other browser tab's open edit form exactly like a normal
     * save would; a form-layer-only bump would miss that path entirely.
     *
     * saving() fires for both creating and updating, so this is gated to
     * $page->exists (skip brand-new rows: their lock_version is the schema
     * default, 0, and there is no earlier version to invalidate) and to
     * isDirty() on something other than lock_version itself — otherwise a
     * bare `$page->save()` with nothing changed (or a caller that only
     * touches lock_version, which nothing in this codebase does) would keep
     * incrementing forever.
     *
     * This is a "check-then-save" scheme, not a real atomic compare-and-swap:
     * PageForm::save() reads the current lock_version, then this hook writes
     * the bumped value in the same request's save() call — there is a window
     * between the read and the write where a concurrent request could slip
     * in. That's an accepted trade-off, not an oversight: the admin UI is
     * guarding against an editor's form going stale over minutes (another
     * person edited the same page meanwhile), not against two requests
     * racing on the same page within milliseconds — a lost-update window that
     * narrow would need row-level locking (SELECT ... FOR UPDATE) around the
     * whole read-modify-write, which nothing else in this admin does either.
     */
    public function saving(Page $page): void
    {
        if (! $page->exists) {
            return;
        }

        $onlyLockVersionDirty = $page->isDirty() && array_keys($page->getDirty()) === ['lock_version'];

        if ($page->isDirty() && ! $onlyLockVersionDirty) {
            $page->lock_version = $page->lock_version + 1;
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
