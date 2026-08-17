<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health;

/**
 * Ordered worst-to-best is NOT the declaration order here — callers that
 * need "worst status wins" (HealthReport::exitCode()) compare against
 * Error explicitly rather than relying on enum case order.
 */
enum HealthStatus: string
{
    case Ok = 'ok';
    case Warning = 'warning';
    case Error = 'error';
}
