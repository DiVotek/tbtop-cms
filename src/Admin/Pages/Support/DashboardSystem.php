<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Stat;

/**
 * Row — server metrics and environment info. Thin facade over:
 * - {@see SystemMetrics}: live-polling CPU/memory/disk stat cards
 * - {@see SystemMemory}: host-vs-PHP-process memory detection
 * - {@see SystemMetricsHistory}: cache ring buffer behind the sparklines
 * - {@see SystemEnvironment}: static version/infrastructure card-lists
 */
class DashboardSystem
{
    /** @return list<Stat> */
    public static function metrics(S $s): array
    {
        return SystemMetrics::cards($s);
    }

    /** @return list<Node> */
    public static function environmentCards(S $s): array
    {
        return SystemEnvironment::cards($s);
    }
}
