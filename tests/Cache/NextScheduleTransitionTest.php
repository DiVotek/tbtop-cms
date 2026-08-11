<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tbtop\Cms\Cache\CmsCache;
use Tbtop\Cms\Cache\NextScheduleTransition;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array', 'tbtop-cms.cache.enabled' => true, 'tbtop-cms.cache.ttl' => 3600]);
});

it('returns null when no published page has a future schedule transition', function (): void {
    Page::factory()->create(['status' => PageStatus::Draft, 'published_at' => now()->addHour()]);
    Page::factory()->create(['status' => PageStatus::Published]);

    expect(NextScheduleTransition::seconds(app(CmsCache::class)))->toBeNull();
});

it('returns the seconds until the nearest future published_at', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->addSeconds(120),
    ]);

    $seconds = NextScheduleTransition::seconds(app(CmsCache::class));

    expect($seconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(120);
});

it('returns the seconds until the nearest future unpublish_at', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'unpublish_at' => now()->addSeconds(60),
    ]);

    $seconds = NextScheduleTransition::seconds(app(CmsCache::class));

    expect($seconds)->toBeGreaterThan(0)->toBeLessThanOrEqual(60);
});

it('picks the nearer of a future published_at and unpublish_at across different pages', function (): void {
    Page::factory()->create(['status' => PageStatus::Published, 'published_at' => now()->addSeconds(500)]);
    Page::factory()->create(['status' => PageStatus::Published, 'unpublish_at' => now()->addSeconds(50)]);

    $seconds = NextScheduleTransition::seconds(app(CmsCache::class));

    expect($seconds)->toBeLessThanOrEqual(50);
});

it('reports a decayed remaining distance when read later within the cached entry lifetime', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->addSeconds(100),
    ]);
    $cache = app(CmsCache::class);

    // Prime the cached transition timestamp at T.
    expect(NextScheduleTransition::seconds($cache))->toBeLessThanOrEqual(100);

    test()->travel(60)->seconds();

    // A read at T+60 must see the real remaining distance (~40s), not the
    // originally cached "100 seconds away" — a rememberLive() TTL computed
    // from the stale distance would outlive the transition.
    expect(NextScheduleTransition::seconds($cache))->toBeLessThanOrEqual(40);
});

it('caps rememberLive TTL to the schedule distance when a page is scheduled soon', function (): void {
    Page::factory()->create([
        'status' => PageStatus::Published,
        'published_at' => now()->addSeconds(10),
    ]);
    $cache = app(CmsCache::class);
    $calls = 0;

    $cache->rememberLive('probe', function () use (&$calls) {
        $calls++;

        return 'v1';
    });

    test()->travel(11)->seconds();

    $cache->rememberLive('probe', function () use (&$calls) {
        $calls++;

        return 'v2';
    });

    // The cache entry must have expired by the time the schedule transition
    // passed, forcing a second closure run instead of serving stale 'v1'.
    expect($calls)->toBe(2);
});

it('clamps to the minimum TTL instead of null while the memoized transition sits at/past the boundary', function (): void {
    $cache = app(CmsCache::class);

    // Seed the memo with a transition timestamp that has JUST elapsed —
    // the state a memo entry is in when second-flooring lets it outlive its
    // own transition by a fraction of a second. Interpreting it as null
    // ("no transition") would hand a freshly written rememberLive() entry
    // the full default TTL while a later transition (still unknown until
    // the memo expires and recomputes) may be minutes away — the
    // bot-reported boundary bug. It must clamp to the 1s minimum instead.
    $versionedKey = (function (string $key): string {
        /** @var CmsCache $this */
        return $this->versionedKey($key); // @phpstan-ignore method.private
    })->call($cache, 'tbtop-cms:next-schedule-transition');
    Cache::store('array')->put($versionedKey, [now()->getTimestamp()], 60);

    expect(NextScheduleTransition::seconds($cache))->toBe(1);
});

it('uses the configured ttl for rememberLive when no page has a future schedule transition', function (): void {
    config(['tbtop-cms.cache.ttl' => 3600]);

    expect(NextScheduleTransition::seconds(app(CmsCache::class)))->toBeNull();

    $cache = app(CmsCache::class);
    $calls = 0;
    $cache->rememberLive('probe', function () use (&$calls) {
        $calls++;

        return 'v1';
    });

    // No schedule constraint: still well within the configured ttl a moment later.
    $cache->rememberLive('probe', function () use (&$calls) {
        $calls++;

        return 'v2';
    });

    expect($calls)->toBe(1);
});
