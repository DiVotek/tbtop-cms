<?php

declare(strict_types=1);

namespace Tbtop\Cms\NotFound\Events;

/**
 * Dispatched from PageController past redirect resolution — a path that
 * resolves to a redirect is a hit, not a miss, and must never reach here.
 * Named PageNotFound rather than NotFoundHit to avoid colliding with the
 * model that stores the aggregated counts this event feeds.
 */
final class PageNotFound
{
    public function __construct(
        public readonly string $path,
        public readonly string $locale,
        public readonly ?string $referer,
        public readonly ?string $userAgent,
    ) {}
}
