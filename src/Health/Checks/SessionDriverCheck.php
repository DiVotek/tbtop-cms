<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Two session drivers the admin panel cannot rely on.
 *
 * "cookie" keeps the whole session inside one signed cookie, so everything
 * it holds competes for the browser's ~4KB per-cookie limit. The page
 * editor is the first place that overflows it: block-tree validation errors
 * and old() input for a large form are flashed together, and once the
 * payload no longer fits, the write is dropped — the redirect lands with no
 * errors and no old input, so the form silently resets instead of showing
 * what was wrong.
 *
 * "array" discards the session at the end of the request, so nothing
 * survives a redirect at all — it exists for tests, and reaching a real
 * install means SESSION_DRIVER was never configured.
 *
 * Both are warnings, not errors: the panel still loads and most of it
 * works. Neither is gated on the environment, since the failure reproduces
 * identically in local development.
 */
final class SessionDriverCheck implements HealthCheck
{
    public function id(): string
    {
        return 'session_driver';
    }

    public function run(): HealthCheckResult
    {
        $driver = (string) config('session.driver', '');

        if ($driver === 'cookie' || $driver === 'array') {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.session_driver.label',
                messageKey: "tbtop-cms::health.checks.session_driver.{$driver}",
                hintKey: "tbtop-cms::health.checks.session_driver.hints.{$driver}",
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.session_driver.label',
            messageKey: 'tbtop-cms::health.checks.session_driver.ok',
            messageReplace: ['driver' => $driver === '' ? '(empty)' : $driver],
        );
    }
}
