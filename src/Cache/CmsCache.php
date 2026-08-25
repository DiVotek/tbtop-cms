<?php

declare(strict_types=1);

namespace Tbtop\Cms\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Single cache facade for the package's frontend content reads. Entries are
 * namespaced under a version counter (`tbtop-cms:content-version`) rather
 * than invalidated individually: any domain write bumps the version
 * (see InvalidatesContentCache), which makes every previously-cached key
 * unreachable without needing to know which keys existed. Cheaper than
 * per-key invalidation and correct as long as reads always go through
 * remember()/rememberLive() with the current version.
 */
final class CmsCache
{
    private const string VERSION_KEY = 'tbtop-cms:content-version';

    private ?int $version = null;

    /**
     * @template TCacheValue
     *
     * @param  Closure(): TCacheValue  $fn
     * @return TCacheValue
     */
    public function remember(string $key, Closure $fn): mixed
    {
        if (! $this->enabled()) {
            return $fn();
        }

        return $this->store()->remember($this->versionedKey($key), $this->ttl(), $fn);
    }

    /**
     * Like remember(), but the TTL is additionally capped so a cached entry
     * never outlives the nearest upcoming page schedule transition
     * (published_at/unpublish_at) — content that depends on a page's live
     * scope (e.g. the sitemap) must not read stale past that instant.
     *
     * @template TCacheValue
     *
     * @param  Closure(): TCacheValue  $fn
     * @return TCacheValue
     */
    public function rememberLive(string $key, Closure $fn): mixed
    {
        if (! $this->enabled()) {
            return $fn();
        }

        $ttl = min($this->ttl(), NextScheduleTransition::seconds($this) ?? $this->ttl());

        return $this->store()->remember($this->versionedKey($key), max(1, $ttl), $fn);
    }

    /**
     * Low-level entry point for callers (NextScheduleTransition) that need
     * the TTL to depend on the freshly computed value itself — Repository's
     * remember() accepts a Closure for $ttl and evaluates it only on a miss,
     * after $fn() has run, so this stays a single cache read on a hit and
     * never runs $fn() just to decide a TTL.
     *
     * $fn is allowed to return null (NextScheduleTransition::compute() does,
     * for "no transition scheduled") — Repository::get() cannot distinguish
     * a cached null from a miss, so Repository::remember() would treat every
     * cached null as still-missing and re-run $fn() on every call. The
     * sentinel wrapper below makes null a real, cacheable value.
     *
     * @template TCacheValue
     *
     * @param  Closure(TCacheValue): int  $ttlFor
     * @param  Closure(): TCacheValue  $fn
     * @return TCacheValue
     */
    public function rememberWithTtl(string $key, Closure $ttlFor, Closure $fn): mixed
    {
        if (! $this->enabled()) {
            return $fn();
        }

        /** @var array{0: mixed} $boxed */
        $boxed = $this->store()->remember(
            $this->versionedKey($key),
            fn (array $boxed): int => max(1, $ttlFor($boxed[0])),
            fn (): array => [$fn()],
        );

        return $boxed[0];
    }

    public function defaultTtl(): int
    {
        return $this->ttl();
    }

    /**
     * Bumps the content version, invalidating every previously cached
     * versioned key. increment() is atomic and preferred, but some stores
     * (e.g. database) return false instead of seeding the key when it
     * doesn't exist yet. Atomically seed zero in that case, then retry the
     * increment; a concurrent initializer winning add() is harmless.
     */
    public function bump(): void
    {
        $store = $this->store();

        if ($store->increment(self::VERSION_KEY) === false) {
            $store->add(self::VERSION_KEY, 0, PHP_INT_MAX);
            $store->increment(self::VERSION_KEY);
        }

        $this->version = null;
    }

    private function versionedKey(string $key): string
    {
        return sprintf('tbtop-cms:v%d:%s', $this->version(), $key);
    }

    /** Memoized per instance (scoped: once per request) so remember() never costs more than one cache read for the version. */
    private function version(): int
    {
        if ($this->version === null) {
            $this->version = $this->readVersion($this->store());
        }

        return $this->version;
    }

    /**
     * A missing/uninitialized version key reads as 0 — matching what
     * increment() seeds a missing key to on stores that support seeding it
     * (array, file), so the very first bump() is guaranteed to change the
     * effective version instead of landing back on the same read-as-1 default.
     */
    private function readVersion(Repository $store): int
    {
        $value = $store->get(self::VERSION_KEY);

        return is_numeric($value) ? (int) $value : 0;
    }

    private function enabled(): bool
    {
        return (bool) config('tbtop-cms.cache.enabled', true);
    }

    private function ttl(): int
    {
        return (int) config('tbtop-cms.cache.ttl', 3600);
    }

    private function store(): Repository
    {
        /** @var string|null $name */
        $name = config('tbtop-cms.cache.store');

        return $name === null ? Cache::store() : Cache::store($name);
    }
}
