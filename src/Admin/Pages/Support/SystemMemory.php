<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

/**
 * Memory usage, in MB. Linux: real host usage from /proc/meminfo
 * (MemTotal - MemAvailable). Elsewhere (macOS dev boxes, containers without
 * /proc — anywhere /proc/meminfo isn't readable): falls back to the PHP
 * process's own memory_get_usage(). That fallback is *not* system memory;
 * the dashboard label ({@see SystemMetrics}) says "PHP process" so it can't
 * be mistaken for host RAM.
 */
class SystemMemory
{
    private const FALLBACK_LIMIT_BYTES = 512 * 1024 * 1024;

    public static function usedMb(): float
    {
        $meminfo = self::procMeminfo();
        if ($meminfo !== null) {
            return ($meminfo['MemTotal'] - $meminfo['MemAvailable']) / 1024;
        }

        return memory_get_usage(true) / 1024 / 1024;
    }

    public static function totalMb(): float
    {
        $meminfo = self::procMeminfo();
        if ($meminfo !== null) {
            return $meminfo['MemTotal'] / 1024;
        }

        return self::parseIniBytes(ini_get('memory_limit')) / 1024 / 1024;
    }

    /** True when the dashboard is reporting real host memory rather than the PHP-process fallback. */
    public static function isHostMemory(): bool
    {
        return self::procMeminfo() !== null;
    }

    /** @return array{MemTotal: int, MemAvailable: int}|null Values in KB. */
    private static function procMeminfo(): ?array
    {
        if (! is_readable('/proc/meminfo')) {
            return null;
        }

        $contents = (string) file_get_contents('/proc/meminfo');
        preg_match('/MemTotal:\s+(\d+)/', $contents, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $contents, $available);

        if (! isset($total[1], $available[1])) {
            return null;
        }

        return ['MemTotal' => (int) $total[1], 'MemAvailable' => (int) $available[1]];
    }

    private static function parseIniBytes(string | false $value): float
    {
        if ($value === false || $value === '-1' || $value === '') {
            return self::FALLBACK_LIMIT_BYTES;
        }

        $unit = strtolower(substr($value, -1));
        $number = (float) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
