<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * Reads the cache key CmsServiceProvider's everyMinute() schedule callback
 * writes (see ScheduleWiring::wireSchedulerHeartbeat()). A missing key doesn't distinguish
 * "scheduler never configured" from "scheduler configured seconds ago, has
 * not ticked yet" — both look identical from here — so a fresh install must
 * not read as broken; the hint says so explicitly rather than alarming an
 * operator mid-install.
 */
final class SchedulerHeartbeatCheck implements HealthCheck
{
    public const string CACHE_KEY = 'tbtop-cms:health:scheduler-heartbeat';

    private const int STALE_AFTER_MINUTES = 5;

    public function id(): string
    {
        return 'scheduler_heartbeat';
    }

    public function run(): HealthCheckResult
    {
        $raw = Cache::get(self::CACHE_KEY);

        if (! is_string($raw) || $raw === '') {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.scheduler_heartbeat.label',
                messageKey: 'tbtop-cms::health.checks.scheduler_heartbeat.missing',
                hintKey: 'tbtop-cms::health.checks.scheduler_heartbeat.hints.missing',
            );
        }

        $lastBeat = CarbonImmutable::parse($raw);
        $ageMinutes = $lastBeat->diffInMinutes(CarbonImmutable::now());

        if ($ageMinutes > self::STALE_AFTER_MINUTES) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.scheduler_heartbeat.label',
                messageKey: 'tbtop-cms::health.checks.scheduler_heartbeat.stale',
                messageReplace: ['minutes' => (int) floor($ageMinutes)],
                hintKey: 'tbtop-cms::health.checks.scheduler_heartbeat.hints.stale',
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.scheduler_heartbeat.label',
            messageKey: 'tbtop-cms::health.checks.scheduler_heartbeat.ok',
        );
    }
}
