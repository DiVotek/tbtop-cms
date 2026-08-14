<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthCheckRunner;
use Tbtop\Cms\Health\HealthStatus;

uses(RefreshDatabase::class);

afterEach(function (): void {
    $robots = public_path('robots.txt');
    if (is_file($robots)) {
        unlink($robots);
    }
});

it('exits 0 and lists check labels when nothing is at error level', function (): void {
    // A bare Testbench app fails several of the real checks (no @include in
    // the fixture root view, no configured frontend routes, etc.) — those
    // are correct findings, not test bugs, so the exit-0 contract is proven
    // here against a controlled ok/warning-only check list instead of
    // fighting the fixture into an artificially "healthy" shape.
    $ok = new class implements HealthCheck
    {
        public function id(): string
        {
            return 'stub_ok';
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
            return 'stub_warning';
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
    $this->app->instance(HealthCheckRunner::class, new HealthCheckRunner([$ok, $warning]));

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.robots_txt.label'))
        ->assertExitCode(0);
});

it('exits 1 and surfaces the failing check label when an error-level check fails', function (): void {
    file_put_contents(public_path('robots.txt'), "User-agent: *\n");

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.robots_txt.label'))
        ->assertExitCode(1);
});
