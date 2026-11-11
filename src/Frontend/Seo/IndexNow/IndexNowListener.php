<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\IndexNow;

use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Models\Page;

/**
 * Picks the page writes search engines should hear about: a page that is
 * publicly indexable after the write, or one that just stopped being so
 * (unpublished, deleted, switched to noindex, scheduled out). A draft that
 * stays a draft, a noindex page and an error page are never submitted.
 *
 * "Was indexable" is read from the write's own changes: the events carry the
 * saved model, whose getChanges() still holds what this save changed.
 */
final readonly class IndexNowListener
{
    public function __construct(
        private IndexNowSettings $settings,
        private IndexNowSubmitter $submitter,
    ) {}

    public function handle(PageSaved | PageUnpublished | PageDeleted $event): void
    {
        $page = $event->page;

        if ($page->isErrorPage() || ! $this->settings->active()) {
            return;
        }

        match (true) {
            $event instanceof PageDeleted => $this->deleted($page),
            $event instanceof PageUnpublished => $this->unpublished($page),
            default => $this->saved($page),
        };
    }

    private function saved(Page $page): void
    {
        if (($page->isLive() && $page->is_indexable) || self::wasLiveAndIndexable($page)) {
            $this->submitter->queuePage($page);
        }
    }

    private function unpublished(Page $page): void
    {
        if (self::wasLiveAndIndexable($page)) {
            $this->submitter->queuePage($page);
        }
    }

    /** A soft-deleted page's slugs are gone after the delete; PageObserver::deleting() keeps them loaded on the model. */
    private function deleted(Page $page): void
    {
        if ($page->isLive() && $page->is_indexable) {
            $this->submitter->queuePageNow($page);
        }
    }

    /**
     * Whether the page was publicly indexable before this write: the same
     * model with the raw values getPrevious() kept for the changed columns.
     * getOriginal() cannot be used, as the event may run after a transaction
     * commits, once the original is already synced.
     */
    private static function wasLiveAndIndexable(Page $page): bool
    {
        if ($page->wasRecentlyCreated && $page->getPrevious() === []) {
            return false;
        }

        $before = clone $page;
        $before->setRawAttributes(array_merge($page->getAttributes(), $page->getPrevious()));

        return $before->isLive() && (bool) $before->is_indexable;
    }
}
