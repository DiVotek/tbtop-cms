<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health;

/**
 * One diagnostic check. Implementations must not throw — a check that
 * cannot complete should return an Error result describing why, rather
 * than let HealthCheckRunner's try/catch turn it into a generic "threw an
 * exception" message. The try/catch exists as a last-resort safety net for
 * bugs, not as the intended error-reporting path.
 */
interface HealthCheck
{
    /** Stable identifier, unique across all registered checks (e.g. "robots_txt"). */
    public function id(): string;

    public function run(): HealthCheckResult;
}
