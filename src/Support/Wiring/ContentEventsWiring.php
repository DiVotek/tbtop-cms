<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Wiring;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Tbtop\Cms\Cache\InvalidatesContentCache;
use Tbtop\Cms\Collection\CollectionEntries;
use Tbtop\Cms\Collection\Events\EntriesUpdated;
use Tbtop\Cms\Collection\Events\EntryDeleted;
use Tbtop\Cms\Collection\Events\EntrySaved;
use Tbtop\Cms\Frontend\Seo\IndexNow\IndexNowListener;
use Tbtop\Cms\Language\Events\LanguagesChanged;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Layout\Events\LayoutSaved;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PagePublished;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Events\PageUnpublished;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;
use Tbtop\Cms\Page\Observers\PageRefs;
use Tbtop\Cms\Page\Observers\PageRevisions;
use Tbtop\Cms\Page\Support\PageSlugWriter;
use Tbtop\Cms\Redirect\Observers\PageSlugRedirects;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\SharedBlock\Events\SharedBlockDeleted;
use Tbtop\Cms\SharedBlock\Events\SharedBlockSaved;
use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * Observers and listeners that keep derived content data in step with saves:
 * slugs for a newly active locale, redirects, revisions, the references index,
 * the content cache version, and IndexNow submissions.
 *
 * Called from CmsServiceProvider::packageBooted().
 */
final class ContentEventsWiring
{
    public static function wire(Application $app): void
    {
        self::wireLocaleSlugBackfill();
        self::wireRedirectMaintenance();
        self::wirePageRevisions();
        self::wirePageRefs();
        self::wireContentCacheInvalidation($app);
        self::wireIndexNow();
    }

    private static function wireLocaleSlugBackfill(): void
    {
        Language::saved(function (Language $language): void {
            $activated = $language->wasRecentlyCreated
                ? $language->is_frontend_active
                : ($language->wasChanged('is_frontend_active') && $language->is_frontend_active);

            if (! $activated) {
                return;
            }

            app(Languages::class)->refresh();
            app(PageSlugWriter::class)->backfillLocale($language->locale);
        });
    }

    private static function wireRedirectMaintenance(): void
    {
        PageSlug::observe(PageSlugRedirects::class);
    }

    /**
     * A second Page observer, registered independently of PageObserver's
     * #[ObservedBy] attribute — see PageRevisions' class docblock for why.
     */
    private static function wirePageRevisions(): void
    {
        Page::observe(PageRevisions::class);
    }

    /** A third Page observer, same reasoning: the references index is derived data. */
    private static function wirePageRefs(): void
    {
        Page::observe(PageRefs::class);
    }

    /** Search engines hear about public page changes; IndexNowListener decides which. */
    private static function wireIndexNow(): void
    {
        foreach ([PageSaved::class, PageUnpublished::class, PageDeleted::class] as $event) {
            Event::listen($event, IndexNowListener::class);
        }
    }

    /**
     * Every domain event that changes frontend-visible content bumps the
     * content cache version — see InvalidatesContentCache's docblock for why
     * one blanket bump instead of per-event targeted invalidation.
     * SubmissionReceived is intentionally excluded: submissions aren't content.
     */
    private static function wireContentCacheInvalidation(Application $app): void
    {
        // The per-request shared-block memo must not outlive a save in the same request (editor actions re-render).
        foreach ([SharedBlockSaved::class, SharedBlockDeleted::class] as $event) {
            Event::listen($event, fn () => $app->make(SharedBlocks::class)->forget());
        }
        // Same for the per-request collection memo.
        foreach ([EntrySaved::class, EntryDeleted::class, EntriesUpdated::class] as $event) {
            Event::listen($event, fn () => $app->make(CollectionEntries::class)->forget());
        }

        foreach ([
            PageSaved::class,
            PagePublished::class,
            PageUnpublished::class,
            PageDeleted::class,
            PageRestored::class,
            LayoutSaved::class,
            SharedBlockSaved::class,
            SharedBlockDeleted::class,
            EntrySaved::class,
            EntryDeleted::class,
            EntriesUpdated::class,
            SettingsSaved::class,
            LanguagesChanged::class,
        ] as $event) {
            Event::listen($event, InvalidatesContentCache::class);
        }
    }
}
