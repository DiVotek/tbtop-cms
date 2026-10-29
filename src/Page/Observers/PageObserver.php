<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use DomainException;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;
use Tbtop\Cms\Page\Support\PageHierarchy;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Page\Support\PageTypePairProblem;
use Tbtop\Cms\Support\LockVersion;
use WeakMap;

class PageObserver
{
    public function __construct(
        private readonly PageTypeRegistry $registry,
        private readonly PageSlugWriter $slugs,
    ) {}

    /**
     * Saves in flight that write no column: Eloquent fires no `updated` for
     * them, so saved() raises PageSaved itself when their slug sync changes
     * a row (a slug-only form save). Static: observer methods resolve a new
     * instance per call.
     *
     * @var WeakMap<Page, true>|null
     */
    private static ?WeakMap $columnlessSaves = null;

    public function saved(Page $page): void
    {
        $overrides = $page->pendingSlugOverrides;
        $page->pendingSlugOverrides = [];
        $columnless = self::$columnlessSaves?->offsetExists($page) ?? false;
        self::$columnlessSaves?->offsetUnset($page);

        // deleting() removed a trashed page's slugs and restored() brings
        // them back; a save in between (e.g. SharedBlockDetacher on a trashed
        // container) must not re-claim the path while the page 404s.
        if ($page->trashed()) {
            return;
        }

        if ($this->slugs->sync($page, $overrides) && $columnless) {
            self::dispatch($page, wasCreated: false, statusBefore: null);
        }
    }

    /**
     * PageSaved is bound to the write rather than to each caller: the content
     * cache bump rides on it (ContentEventsWiring::wireContentCacheInvalidation()),
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
        // The bump is its own statement (LockVersion), so lock_version is a
        // change here only when a caller wrote it alone: not a content write.
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

        // After the guards, so a rejected write leaves the version alone.
        if (self::changesColumns($page)) {
            $page->expectedLockVersion === null
                ? LockVersion::bump($page)
                : self::takeLock($page, write: true);
        }
    }

    /**
     * lock_version moves on every real update, at the model rather than in
     * PageForm::save(), so non-form writers (revision restore, shared-block
     * detach, starter sync, untrash) invalidate open edit forms too. Every
     * bump is one SQL statement (LockVersion), never `loaded + 1` in PHP:
     *
     * - A form save carries expectedLockVersion: updating() claims the row
     *   with a conditional UPDATE, so of two saves loaded at one version
     *   exactly one wins; a claim matching no row is PageConflictException.
     * - A form save that changes no column only compares here, so a no-op
     *   Save keeps other open forms valid; a slug override that changes the
     *   stored slug still claims.
     * - Any other writer bumps relatively and never conflicts.
     *
     * New rows keep the schema default (0). The caller owns the transaction
     * that rolls a claim back when a later step of the save fails.
     */
    public function saving(Page $page): void
    {
        if ($page->isDirty(['type', 'subtype'])) {
            $this->guardTypeAndSubtype($page);
        }

        self::stampFirstPublish($page);

        // Recomputed on every save, so a save that threw before saved()
        // leaves no stale mark for the next one.
        self::$columnlessSaves ??= new WeakMap;
        if ($page->exists && ! self::changesColumns($page)) {
            self::$columnlessSaves[$page] = true;
        } else {
            self::$columnlessSaves->offsetUnset($page);
        }

        if (! $page->exists || $page->expectedLockVersion === null || self::changesColumns($page)) {
            return;
        }

        self::takeLock($page, write: $this->slugs->overridesChange($page, $page->pendingSlugOverrides));
    }

    /**
     * The first publish with no date set stamps published_at with now, so the
     * page has a publish time for its datePublished. A date the editor set
     * (including a future one, which schedules the page) is left alone, and a
     * republish keeps the stamp from the first publish: unpublishing does not
     * clear published_at.
     */
    private static function stampFirstPublish(Page $page): void
    {
        if ($page->status === PageStatus::Published && $page->published_at === null && $page->isDirty('status')) {
            $page->published_at = now();
        }
    }

    private static function changesColumns(Page $page): bool
    {
        return $page->isDirty() && array_keys($page->getDirty()) !== ['lock_version'];
    }

    private static function takeLock(Page $page, bool $write): void
    {
        LockVersion::take(
            $page,
            (int) $page->expectedLockVersion,
            $write,
            static fn (): PageConflictException => new PageConflictException("Page #{$page->getKey()} was changed since this form was loaded."),
        );
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
     *
     * The tree may also have changed around the trashed page (its parent's
     * type, an ancestor moved deeper): assertChildrenAllowed() and the depth
     * walk only see live pages, so the full assert runs again here. None of
     * its queries reads this page's own row, so deleted_at not being cleared
     * yet does not matter.
     */
    public function restoring(Page $page): void
    {
        if ($page->parent_id !== null) {
            $parent = Page::withTrashed()->find($page->parent_id);

            if ($parent !== null && $parent->trashed()) {
                throw new DomainException(__('tbtop-cms::pages.validation.restore_parent_trashed'));
            }
        }

        PageHierarchy::assert($page, $this->registry);
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

    /**
     * `type` holds a base and `subtype` one of that base's subtypes, for every
     * writer (seeders and host code included), not only the admin form. Run
     * only when the pair changes: a stored subtype outlives its registration
     * like `type` does, and an unregistered `type` is PageHierarchy's to reject.
     */
    private function guardTypeAndSubtype(Page $page): void
    {
        $type = (string) $page->getAttribute('type');
        $subtype = $page->subtype;

        match (PageTypePairProblem::of($type, $subtype, $this->registry)) {
            null => null,
            PageTypePairProblem::TypeIsSubtype => throw new DomainException(__('tbtop-cms::pages.validation.type_is_subtype', [
                'type' => $type, 'base' => $this->registry->get($type)->base(),
            ])),
            PageTypePairProblem::SubtypeInvalid => throw new DomainException(__('tbtop-cms::pages.validation.subtype_invalid', [
                'subtype' => $subtype, 'type' => $type,
            ])),
        };
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
