<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Cms;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Events\PageSaved;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Settings\Events\SettingsSaved;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.cache.enabled' => true, 'tbtop-cms.cache.ttl' => 3600]);
    Language::factory()->default()->create(['locale' => 'en']);
    app(Cms::class)->frontendRoutes();
});

it('does not re-query pages on a second request when the cache is warm', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/sitemap.xml')->assertOk();

    $countBefore = 0;
    DB::listen(function ($query) use (&$countBefore): void {
        if (str_contains($query->sql, 'tbtop_pages')) {
            $countBefore++;
        }
    });

    $this->get('/sitemap.xml')->assertOk();

    expect($countBefore)->toBe(0);
});

it('invalidates the cached sitemap when a page is saved', function (): void {
    $existing = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    // The URLs live in the chunk; sitemap.xml is the index above it.
    $first = $this->get('/sitemap-1.xml')->assertOk()->getContent();
    expect($first)->toContain(url($existing->pathFor('en')));

    // A page created after the first request could not possibly be in a
    // response served from the first request's cached XML — a URL for it
    // showing up in the second response proves the cache was invalidated
    // and the sitemap rebuilt, not merely that the page exists. Page::factory()
    // ->create() alone does not dispatch PageSaved (only PageForm::save() and
    // the other real write paths do — see CmsServiceProvider::
    // wireContentCacheInvalidation()), so it's dispatched explicitly here to
    // mirror what a real save does.
    $newPage = Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'Pricing'], 'is_indexable' => true]);
    event(new PageSaved($newPage, wasCreated: true));

    $second = $this->get('/sitemap-1.xml')->assertOk()->getContent();

    expect($second)->toContain(url($newPage->pathFor('en')))
        ->and($first)->not->toContain(url($newPage->pathFor('en')));
});

it('reshapes the cached sitemap when the chunk size setting changes', function (): void {
    foreach (['One', 'Two', 'Three'] as $name) {
        Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => $name], 'is_indexable' => true]);
    }

    // Warm both documents at the default size, then change the budget through
    // the same path the settings form uses.
    expect($this->get('/sitemap.xml')->assertOk()->getContent())->not->toContain(url('sitemap-2.xml'));
    $this->get('/sitemap-1.xml')->assertOk();

    app(Settings::class)->set('seo.sitemap_per_chunk', 2);
    event(new SettingsSaved(['seo.sitemap_per_chunk' => 2]));

    // Chunk boundaries are baked into cached XML, so a settings save has to
    // reach both the index and every chunk — CmsCache does it by version, not
    // per key (see its docblock), which is what makes this pass.
    expect($this->get('/sitemap.xml')->assertOk()->getContent())->toContain(url('sitemap-2.xml'))
        ->and(substr_count($this->get('/sitemap-1.xml')->assertOk()->getContent(), '<loc>'))->toBe(2);
});

it('builds per request when the cms cache is disabled', function (): void {
    config(['tbtop-cms.cache.enabled' => false]);
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/sitemap.xml')->assertOk();

    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'tbtop_pages')) {
            $count++;
        }
    });

    $this->get('/sitemap.xml')->assertOk();

    expect($count)->toBeGreaterThan(0);
});

it('404s the sitemap when indexing is disabled even with a warm cache', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'name' => ['en' => 'About'], 'is_indexable' => true]);

    $this->get('/sitemap.xml')->assertOk();

    app(Settings::class)->set('seo.indexing_enabled', false);

    $this->get('/sitemap.xml')->assertNotFound();
});

it('shortens the cache TTL to the next scheduled transition instead of the configured ttl', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'name' => ['en' => 'About'],
        'is_indexable' => true,
        'published_at' => now()->addSeconds(10),
    ]);

    $first = $this->get('/sitemap.xml')->assertOk()->getContent();

    test()->travel(11)->seconds();

    $countAfterTransition = 0;
    DB::listen(function ($query) use (&$countAfterTransition): void {
        if (str_contains($query->sql, 'tbtop_pages')) {
            $countAfterTransition++;
        }
    });

    $this->get('/sitemap.xml')->assertOk();

    // The cache entry must have expired by the time the schedule transition
    // passed (10s), forcing a rebuild instead of serving the first response
    // for the full configured ttl (3600s).
    expect($countAfterTransition)->toBeGreaterThan(0);
});
