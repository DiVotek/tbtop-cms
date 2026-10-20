<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Tbtop\Cms\Health\Checks\SchedulerHeartbeatCheck;
use Tbtop\Cms\Health\HealthStatus;

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('warns when no heartbeat has ever been recorded', function (): void {
    $result = (new SchedulerHeartbeatCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning);
});

it('is ok when the heartbeat is fresh', function (): void {
    Cache::forever(SchedulerHeartbeatCheck::CACHE_KEY, now()->toIso8601String());

    $result = (new SchedulerHeartbeatCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('warns when the heartbeat is older than 5 minutes', function (): void {
    Cache::forever(SchedulerHeartbeatCheck::CACHE_KEY, now()->subMinutes(10)->subSeconds(29)->toIso8601String());

    $result = (new SchedulerHeartbeatCheck)->run();

    // Carbon 3's diffInMinutes() is a float; the message shows whole minutes.
    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->message())->toBe(__('tbtop-cms::health.checks.scheduler_heartbeat.stale', ['minutes' => 10]));
});
