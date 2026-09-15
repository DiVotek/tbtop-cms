<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use DomainException;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
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

    /**
     * PageSaved is bound to the write rather than to each caller: the content
     * cache bump rides on it (CmsServiceProvider::wireContentCacheInvalidation()),
     * so a caller-side dispatch left every other write path serving stale
     * content with no error. Same move as LanguagesChanged into LanguageObserver.
     *
     * created()/updated() rather than saved(): wasRecentlyCreated stays true
     * for the rest of the request, so a page created and then updated in one
     * request cannot be told apart from inside saved().
     */
    public function created(Page $page): void
    {
        self::dispatch($page, wasCreated: true, statusBefore: null);
    }

    public function updated(Page $page): void
    {
        // lock_version is bumped by saving() on every real update, so it is
        // never the only change for a write that touched something.
        if (array_keys($page->getChanges()) === ['lock_version']) {
            return;
        }

        self::dispatch(
            $page,
            wasCreated: false,
            statusBefore: $page->wasChanged('status') ? $page->getOriginal('status') : null,
        );
    }

    /**
     * afterCommit so a caller batching writes in a transaction
     * (StarterBlockSync::apply()) emits only once the rows commit; outside a
     * transaction Laravel runs the callback immediately.
     */
    private static function dispatch(Page $page, bool $wasCreated, mixed $statusBefore): void
    {
        DB::afterCommit(static function () use ($page, $wasCreated, $statusBefore): void {
            event(new PageSaved($page, $wasCreated));

            self::dispatchPublishTransition($page, $wasCreated, $statusBefore);
        });
    }

    /** $statusBefore is null when this write did not change status. */
    private static function dispatchPublishTransition(Page $page, bool $wasCreated, mixed $statusBefore): void
    {
        $isPublished = $page->status === PageStatus::Published;

        if ($wasCreated) {
            if ($isPublished) {
                event(new PagePublished($page));
            }

            return;
        }

        if ($statusBefore === null) {
            return;
        }

        $wasPublished = self::asStatus($statusBefore) === PageStatus::Published;

        if ($isPublished && ! $wasPublished) {
            event(new PagePublished($page));
        } elseif ($wasPublished && ! $isPublished) {
            event(new PageUnpublished($page));
        }
    }

    /** getOriginal() returns the cast enum or the raw column value depending on the write path. */
    private static function asStatus(mixed $status): ?PageStatus
    {
        return $status instanceof PageStatus ? $status : PageStatus::tryFrom((string) $status);
    }

    public function creating(Page $page): void
    {
        PageHierarchy::assert($page, $this->registry);
    }

    public function updating(Page $page): void
    {
        $this->guardSystemStatus($page);
        $this->guardErrorClassImmutable($page);

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

    private function guardErrorClassImmutable(Page $page): void
    {
        if ($page->isDirty('error_class')) {
            throw new DomainException(__('tbtop-cms::pages.validation.error_class_immutable'));
        }
    }
}
