<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health;

/** Immutable outcome of running every registered check once. */
final class HealthReport
{
    /** @param  list<HealthCheckResult>  $results */
    public function __construct(public readonly array $results) {}

    public function hasErrors(): bool
    {
        foreach ($this->results as $result) {
            if ($result->status === HealthStatus::Error) {
                return true;
            }
        }

        return false;
    }

    /** Console exit code: 0 unless an error-level check failed (warnings never fail the run). */
    public function exitCode(): int
    {
        return $this->hasErrors() ? 1 : 0;
    }
}
