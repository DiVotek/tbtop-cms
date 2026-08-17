<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * public/robots.txt is served by the webserver before any Laravel route
 * runs, so its mere presence permanently shadows the dynamic
 * `tbtop-cms.robots` route — the SEO settings page's indexing toggle then
 * silently has no effect. See README "public/robots.txt must not exist".
 */
final class RobotsTxtCheck implements HealthCheck
{
    public function id(): string
    {
        return 'robots_txt';
    }

    public function run(): HealthCheckResult
    {
        if (File::exists(public_path('robots.txt'))) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.robots_txt.label',
                messageKey: 'tbtop-cms::health.checks.robots_txt.present',
                hintKey: 'tbtop-cms::health.checks.robots_txt.hints.present',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.robots_txt.label',
            messageKey: 'tbtop-cms::health.checks.robots_txt.ok',
        );
    }
}
