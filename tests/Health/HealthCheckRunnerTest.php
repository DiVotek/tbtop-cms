<?php

declare(strict_types=1);

use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthCheckRunner;
use Tbtop\Cms\Health\HealthStatus;

it('turns a throwing check into an error result instead of crashing the report', function (): void {
    $throwing = new class implements HealthCheck
    {
        public function id(): string
        {
            return 'throwing_check';
        }

        public function run(): HealthCheckResult
        {
            throw new RuntimeException('boom');
        }
    };
    $ok = new class implements HealthCheck
    {
        public function id(): string
        {
            return 'ok_check';
        }

        public function run(): HealthCheckResult
        {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.robots_txt.label',
                messageKey: 'tbtop-cms::health.checks.robots_txt.ok',
            );
        }
    };

    $report = (new HealthCheckRunner([$throwing, $ok]))->run();

    expect($report->results)->toHaveCount(2);

    $failed = $report->results[0];
    expect($failed->checkId)->toBe('throwing_check')
        ->and($failed->status)->toBe(HealthStatus::Error)
        ->and($failed->message())->toContain('boom');

    expect($report->results[1]->status)->toBe(HealthStatus::Ok)
        ->and($report->hasErrors())->toBeTrue()
        ->and($report->exitCode())->toBe(1);
});

it('reports no errors and exit code 0 when every check is ok or warning', function (): void {
    $ok = new class implements HealthCheck
    {
        public function id(): string
        {
            return 'ok_check';
        }

        public function run(): HealthCheckResult
        {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Ok,
                labelKey: 'tbtop-cms::health.checks.robots_txt.label',
                messageKey: 'tbtop-cms::health.checks.robots_txt.ok',
            );
        }
    };
    $warning = new class implements HealthCheck
    {
        public function id(): string
        {
            return 'warning_check';
        }

        public function run(): HealthCheckResult
        {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Warning,
                labelKey: 'tbtop-cms::health.checks.app_url.label',
                messageKey: 'tbtop-cms::health.checks.app_url.ok',
            );
        }
    };

    $report = (new HealthCheckRunner([$ok, $warning]))->run();

    expect($report->hasErrors())->toBeFalse()
        ->and($report->exitCode())->toBe(0);
});

it('resolves the default check list through the container when none are given', function (): void {
    $report = app(HealthCheckRunner::class)->run();

    expect($report->results)->toHaveCount(count(HealthCheckRunner::defaultChecks()));
});
