<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * public/llms.txt shadows the dynamic `tbtop-cms.llms-txt` route the same
 * way public/robots.txt shadows robots.txt (see RobotsTxtCheck). A warning,
 * not an error: a host may ship a hand-written file on purpose, but then the
 * «For AI» tab of the SEO settings page has no effect.
 */
final class LlmsTxtCheck implements HealthCheck
{
    public function id(): string
    {
        return 'llms_txt';
    }

    public function run(): HealthCheckResult
    {
        if (File::exists(public_path('llms.txt'))) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.llms_txt.label',
                messageKey: 'tbtop-cms::health.checks.llms_txt.present',
                hintKey: 'tbtop-cms::health.checks.llms_txt.hints.present',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.llms_txt.label',
            messageKey: 'tbtop-cms::health.checks.llms_txt.ok',
        );
    }
}
