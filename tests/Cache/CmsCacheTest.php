<?php

declare(strict_types=1);

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Tbtop\Cms\Cache\CmsCache;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('bypasses the cache and always runs the closure when disabled', function (): void {
    config(['tbtop-cms.cache.enabled' => false]);
    $cache = app(CmsCache::class);
    $calls = 0;

    $first = $cache->remember('key', function () use (&$calls) {
        $calls++;

        return 'value-' . $calls;
    });
    $second = $cache->remember('key', function () use (&$calls) {
        $calls++;

        return 'value-' . $calls;
    });

    expect($first)->toBe('value-1')
        ->and($second)->toBe('value-2')
        ->and($calls)->toBe(2);
});

it('caches the closure result across calls when enabled', function (): void {
    config(['tbtop-cms.cache.enabled' => true]);
    $cache = app(CmsCache::class);
    $calls = 0;

    $first = $cache->remember('key', function () use (&$calls) {
        $calls++;

        return 'value-' . $calls;
    });
    $second = $cache->remember('key', function () use (&$calls) {
        $calls++;

        return 'value-' . $calls;
    });

    expect($first)->toBe('value-1')
        ->and($second)->toBe('value-1')
        ->and($calls)->toBe(1);
});

it('serves a fresh closure result for the same key after bump()', function (): void {
    config(['tbtop-cms.cache.enabled' => true]);
    $cache = app(CmsCache::class);
    $calls = 0;
    $fn = function () use (&$calls) {
        $calls++;

        return 'value-' . $calls;
    };

    $first = $cache->remember('key', $fn);
    $cache->bump();
    $second = $cache->remember('key', $fn);

    expect($first)->toBe('value-1')
        ->and($second)->toBe('value-2')
        ->and($calls)->toBe(2);
});

it('retries an atomic increment when another writer initializes the version', function (): void {
    $store = Mockery::mock(Repository::class);
    $store->shouldReceive('increment')->once()->with('tbtop-cms:content-version')->andReturnFalse();
    $store->shouldReceive('add')->once()->with('tbtop-cms:content-version', 0, PHP_INT_MAX)->andReturnFalse();
    $store->shouldReceive('increment')->once()->with('tbtop-cms:content-version')->andReturn(2);
    Cache::shouldReceive('store')->once()->withNoArgs()->andReturn($store);

    (new CmsCache)->bump();
});

it('memoizes the version for the lifetime of one instance, ignoring a bump from another', function (): void {
    config(['tbtop-cms.cache.enabled' => true]);
    // Two separate instances stand in for two separate requests: CmsCache is
    // registered scoped(), so within a single request only one instance
    // is ever resolved and the version is read from the store at most once.
    $requestOne = new CmsCache;
    $requestTwo = new CmsCache;

    $requestOne->remember('a', fn (): string => 'a-value');

    // A write happening mid "request one" (via a different instance) must not
    // change what request one's already-memoized version resolves to.
    $requestTwo->bump();

    expect($requestOne->remember('a', fn (): string => 'a-changed'))->toBe('a-value')
        ->and($requestTwo->remember('a', fn (): string => 'a-fresh'))->toBe('a-fresh');
});
