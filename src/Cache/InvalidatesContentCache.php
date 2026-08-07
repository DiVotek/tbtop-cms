<?php

declare(strict_types=1);

namespace Tbtop\Cms\Cache;

/**
 * Registered against every domain event that changes frontend-visible
 * content (see CmsServiceProvider::wireContentCacheInvalidation()).
 * A single blanket bump() rather than per-event targeted invalidation:
 * versioned keys make every previously cached entry unreachable regardless
 * of which one actually changed, so precision would only save a cache
 * refill that's about to happen anyway — not worth the bookkeeping at this
 * scale. SubmissionReceived is deliberately not wired here: submissions are
 * data, not content, and don't affect anything CmsCache serves.
 */
final class InvalidatesContentCache
{
    public function __construct(private readonly CmsCache $cache) {}

    public function handle(): void
    {
        $this->cache->bump();
    }
}
