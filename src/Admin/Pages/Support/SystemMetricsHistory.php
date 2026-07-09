<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Cache-backed ring buffer for live system-metric sparklines. Each poll tick
 * appends the current sample and returns the trailing window — history is
 * honest: it only has as many points as polls that have actually happened
 * since the dashboard was opened (or the cache entry expired).
 */
class SystemMetricsHistory
{
    private const MAX_SAMPLES = 30;

    private const CACHE_TTL_SECONDS = 3600;

    private const CACHE_PREFIX = 'tbtop-cms:dashboard:system-metric:';

    /**
     * Append $value to the metric's ring buffer and return the updated series
     * together with the sample that immediately preceded it (null on the
     * first-ever sample), so callers can derive a trend direction from one
     * cache round-trip.
     *
     * @return array{series: list<int|float>, previous: int|float|null}
     */
    public static function append(string $metric, int | float $value): array
    {
        $key = self::cacheKey($metric);
        $series = Cache::get($key, []);
        $previous = $series === [] ? null : $series[count($series) - 1];

        $series[] = $value;
        if (count($series) > self::MAX_SAMPLES) {
            $series = array_slice($series, -self::MAX_SAMPLES);
        }

        Cache::put($key, $series, self::CACHE_TTL_SECONDS);

        return ['series' => $series, 'previous' => $previous];
    }

    private static function cacheKey(string $metric): string
    {
        return self::CACHE_PREFIX . $metric;
    }
}
