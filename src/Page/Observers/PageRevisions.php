<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Observers;

use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageRevisionWriter;

/**
 * Snapshots a Page's editorial content into page_revisions on every write
 * that reaches PageForm::save() — the edit-page save, the create modal, and
 * the instant block add/remove saves (PageEditBlockTabs) all funnel through
 * Page::save(), so these created()/updated() hooks cover every entry point without
 * each caller remembering to snapshot itself.
 *
 * A second, separate observer from PageObserver (wired in
 * ContentEventsWiring::wirePageRevisions(), pattern of wireRedirectMaintenance())
 * rather than added to PageObserver directly: revisions are an optional,
 * config-gated feature bolted onto Page writes, not a structural invariant
 * like PageObserver's slug/hierarchy/system-page guards — keeping it separate
 * means turning revisions off can never risk those guards.
 */
class PageRevisions
{
    public function __construct(private readonly PageRevisionWriter $revisions) {}

    /**
     * 'create' from created() and 'save' from updated() rather than both
     * from saved(): wasRecentlyCreated stays true for the rest of the
     * request, so a later save of the same instance would be recorded as a
     * second 'create'. A revision restore stays in saved() so it is recorded
     * even when the restored content equals the current one (no update).
     */
    public function created(Page $page): void
    {
        if ($this->featureOn()) {
            $this->revisions->snapshot($page, 'create');
        }
    }

    public function saved(Page $page): void
    {
        if ($page->pendingRestore && $this->featureOn()) {
            $this->revisions->snapshot($page, 'restore');
        }
    }

    public function updated(Page $page): void
    {
        if ($page->pendingRestore || ! $this->featureOn()) {
            return;
        }

        // Dedup: a sort_order-only or status-only save (e.g. drag-reordering
        // in the pages list, or a status toggle) must not create a revision —
        // only a change to one of the fields a revision actually snapshots.
        if ($page->wasChanged(PageRevisionWriter::editorialFields())) {
            $this->revisions->snapshot($page, 'save');
        }
    }

    private function featureOn(): bool
    {
        return (int) config('tbtop-cms.revisions.keep', 25) > 0;
    }
}
