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
 * remember() with the current version.
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
     * Bumps the content version, invalidating every previously cached
     * versioned key. increment() is atomic and preferred, but some stores
     * (e.g. database) return false instead of seeding the key when it
     * doesn't exist yet — that miss falls back to a plain get+put.
     */
    public function bump(): void
    {
        $store = $this->store();

        if ($store->increment(self::VERSION_KEY) === false) {
            $store->forever(self::VERSION_KEY, $this->readVersion($store) + 1);
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
