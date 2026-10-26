<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Tbtop\Cms\Api\ApiWiring;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Why the pages API is not answering although `tbtop-cms.api.enabled` is on:
 * laravel/sanctum is not installed (the routes are not registered at all),
 * or the admin user model cannot hold tokens. A warning: the site works.
 */
final class PagesApiCheck implements HealthCheck
{
    private const string KEY = 'tbtop-cms::health.checks.pages_api.';

    public function id(): string
    {
        return 'pages_api';
    }

    public function run(): HealthCheckResult
    {
        if (! ApiWiring::isEnabled()) {
            return $this->result(HealthStatus::Ok, 'off');
        }
        if (! ApiWiring::isSanctumInstalled()) {
            return $this->result(HealthStatus::Warning, 'no_sanctum');
        }

        $model = (string) config('tbtop-cms.admin.model', 'App\\Models\\User');
        if (! class_exists($model) || ! method_exists($model, 'createToken')) {
            return $this->result(HealthStatus::Warning, 'no_tokens', ['model' => $model]);
        }

        return $this->result(HealthStatus::Ok, 'ok');
    }

    /** @param  array<string, string>  $replace */
    private function result(HealthStatus $status, string $state, array $replace = []): HealthCheckResult
    {
        return new HealthCheckResult(
            checkId: $this->id(),
            status: $status,
            labelKey: self::KEY . 'label',
            messageKey: self::KEY . $state,
            messageReplace: $replace,
            hintKey: $status === HealthStatus::Ok ? null : self::KEY . 'hints.' . $state,
            hintReplace: $replace,
        );
    }
}
