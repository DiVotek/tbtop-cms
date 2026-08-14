<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * A leftover scaffold APP_URL (localhost, http://) reaching a production
 * environment corrupts every absolute URL this package generates from it —
 * canonical tags, sitemap entries, OG/Twitter image URLs, the robots.txt
 * sitemap reference — all silently pointing at the wrong host.
 */
final class AppUrlCheck implements HealthCheck
{
    public function id(): string
    {
        return 'app_url';
    }

    public function run(): HealthCheckResult
    {
        if (! app()->environment('production')) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.app_url.label',
                messageKey: 'tbtop-cms::health.checks.app_url.not_production',
                messageReplace: ['environment' => app()->environment()],
            );
        }

        $url = (string) config('app.url', '');
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        $isLocalHost = is_string($host) && (str_contains($host, 'localhost') || $host === '127.0.0.1');
        $isPlainHttp = $scheme === 'http';

        if ($isLocalHost || $isPlainHttp) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.app_url.label',
                messageKey: 'tbtop-cms::health.checks.app_url.suspicious',
                messageReplace: ['url' => $url],
                hintKey: 'tbtop-cms::health.checks.app_url.hints.suspicious',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.app_url.label',
            messageKey: 'tbtop-cms::health.checks.app_url.ok',
            messageReplace: ['url' => $url],
        );
    }
}
