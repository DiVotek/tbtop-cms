<?php

declare(strict_types=1);

namespace Tbtop\Cms\Cache;

use Illuminate\Support\Carbon;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * Seconds until the nearest future published_at/unpublish_at among published
 * pages — the moment a schedule-aware cache entry (CmsCache::rememberLive)
 * must stop being served, since a page's live scope changes at that instant
 * without any write/event firing. The lookup itself is cheap (two indexed
 * min() queries) but still runs on every miss, so it's cached under its own
 * versioned key rather than queried on every rememberLive() call.
 *
 * The cached value is the transition's absolute timestamp, NOT a distance in
 * seconds: a cached distance would stay constant while real time passes, so
 * a rememberLive() call made later in the entry's lifetime would compute its
 * TTL from a stale "N seconds away" and keep serving past the transition.
 * Remaining time is derived from the timestamp at every read instead. The
 * entry's own TTL is still capped to the remaining distance so it expires at
 * the transition and the next read recomputes the following one.
 */
final class NextScheduleTransition
{
    private const string CACHE_KEY = 'tbtop-cms:next-schedule-transition';

    public static function seconds(CmsCache $cache): ?int
    {
        $at = $cache->rememberWithTtl(
            self::CACHE_KEY,
            static fn (?int $at): int => $at === null
                ? $cache->defaultTtl()
                : max(1, $at - Carbon::now()->getTimestamp()),
            static fn (): ?int => self::compute(),
        );

        if ($at === null) {
            return null;
        }

        $remaining = $at - Carbon::now()->getTimestamp();

        return $remaining > 0 ? $remaining : null;
    }

    /** Absolute epoch second of the nearest future transition, or null when none is scheduled. */
    private static function compute(): ?int
    {
        $now = Carbon::now();

        $nextPublish = Page::query()
            ->where('status', PageStatus::Published->value)
            ->where('published_at', '>', $now)
            ->min('published_at');

        $nextUnpublish = Page::query()
            ->where('status', PageStatus::Published->value)
            ->where('unpublish_at', '>', $now)
            ->min('unpublish_at');

        $candidates = array_filter([$nextPublish, $nextUnpublish], static fn (mixed $v): bool => $v !== null);

        if ($candidates === []) {
            return null;
        }

        $earliest = min(array_map(static fn (string $v): Carbon => Carbon::parse($v), $candidates));

        return $earliest->getTimestamp();
    }
}
