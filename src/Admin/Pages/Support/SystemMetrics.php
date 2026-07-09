<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Stat;

/**
 * Live server-load stat cards (CPU / memory / disk). Each polls its own data
 * endpoint every 10s; every tick samples the current value, appends it to a
 * cache-backed ring buffer ({@see SystemMetricsHistory}), and renders the
 * value plus a bottom sparkline built from real history — not synthetic data.
 */
class SystemMetrics
{
    private const POLL_SECONDS = 10;

    /** @return list<Stat> */
    public static function cards(S $s): array
    {
        return [self::cpuCard($s), self::memoryCard($s), self::diskCard($s)];
    }

    private static function cpuCard(S $s): Stat
    {
        $sample = self::sample('cpu-load', self::cpuLoadPercent(...));

        return $s->stat(__('tbtop-cms::dashboard.system.cpu_title'))
            ->value(self::formatPercent($sample['value']))
            ->description(__('tbtop-cms::dashboard.system.cpu_description'))
            ->icon('cpu')
            ->trend($sample['trend'])
            ->sparkline($sample['series'], 'bottom')
            ->sparklineColor('success')
            ->poll(self::POLL_SECONDS);
    }

    private static function memoryCard(S $s): Stat
    {
        $sample = self::sample('memory-used', self::memoryUsedMb(...));
        $total = self::memoryTotalMb();
        $percent = $total > 0 ? round(($sample['value'] / $total) * 100) : 0;
        $descriptionKey = SystemMemory::isHostMemory()
            ? 'tbtop-cms::dashboard.system.memory_description'
            : 'tbtop-cms::dashboard.system.memory_description_php_process';

        return $s->stat(__('tbtop-cms::dashboard.system.memory_title'))
            ->value(sprintf('%d MB / %d MB', round($sample['value']), round($total)))
            ->description(__($descriptionKey, ['percent' => $percent]))
            ->icon('memory-stick')
            ->trend($sample['trend'])
            ->sparkline($sample['series'], 'bottom')
            ->sparklineColor('success')
            ->poll(self::POLL_SECONDS);
    }

    private static function diskCard(S $s): Stat
    {
        $sample = self::sample('disk-used', self::diskUsedGb(...));
        $total = self::diskTotalGb();

        return $s->stat(__('tbtop-cms::dashboard.system.disk_title'))
            ->value(sprintf('%.1f / %.1f GB', $sample['value'], $total))
            ->description(__('tbtop-cms::dashboard.system.disk_description'))
            ->icon('hard-drive')
            ->trend($sample['trend'])
            ->sparkline($sample['series'], 'bottom')
            ->sparklineColor('success')
            ->poll(self::POLL_SECONDS);
    }

    /**
     * Take one sample, append it to the metric's ring buffer, and derive an
     * up/down trend against the previous sample (defaults to 'up' on the
     * very first sample — nothing to compare against yet, series is flat).
     *
     * @return array{value: int|float, series: list<int|float>, trend: 'up'|'down'}
     */
    private static function sample(string $metric, callable $resolver): array
    {
        $value = $resolver();
        $history = SystemMetricsHistory::append($metric, $value);

        $previous = $history['previous'];
        $trend = $previous !== null && $value < $previous ? 'down' : 'up';

        return ['value' => $value, 'series' => $history['series'], 'trend' => $trend];
    }

    private static function formatPercent(int | float $value): string
    {
        return round($value) . ' %';
    }

    /**
     * sys_getloadavg()[0] is the 1-minute load average (PHP exposes no
     * cheaper realtime CPU sampler) — normalized to "% of available cores"
     * by dividing by nproc. Load averages aren't inherently percentages, but
     * this is the closest honest read to topwalls' CPU gauge; the card's
     * description states plainly that it's the 1-minute average.
     */
    private static function cpuLoadPercent(): float
    {
        $load = function_exists('sys_getloadavg') ? sys_getloadavg() : false;
        if ($load === false) {
            return 0.0;
        }

        $cores = max(1, (int) (self::nproc() ?? 1));

        return min(100.0, ($load[0] / $cores) * 100);
    }

    /**
     * Core count without shelling out (banned by the security arch preset):
     * Linux exposes one "processor" line per core in /proc/cpuinfo. No
     * portable equivalent exists on macOS/BSD without exec(), so it falls
     * back to 1 there — the CPU card still renders, just unnormalized.
     */
    private static function nproc(): ?int
    {
        if (! is_readable('/proc/cpuinfo')) {
            return null;
        }

        $count = preg_match_all('/^processor\s*:/m', (string) file_get_contents('/proc/cpuinfo'));

        return $count > 0 ? $count : null;
    }

    private static function memoryUsedMb(): float
    {
        return SystemMemory::usedMb();
    }

    private static function memoryTotalMb(): float
    {
        return SystemMemory::totalMb();
    }

    private static function diskUsedGb(): float
    {
        [$free, $total] = self::diskStats();

        return $total > 0 ? ($total - $free) / 1024 / 1024 / 1024 : 0.0;
    }

    private static function diskTotalGb(): float
    {
        [, $total] = self::diskStats();

        return $total / 1024 / 1024 / 1024;
    }

    /** @return array{0: float, 1: float} [free bytes, total bytes] */
    private static function diskStats(): array
    {
        $free = disk_free_space(storage_path());
        $total = disk_total_space(storage_path());

        return [$free !== false ? $free : 0.0, $total !== false ? $total : 0.0];
    }
}
