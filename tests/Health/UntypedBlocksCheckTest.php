<?php

declare(strict_types=1);

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Health\Checks\UntypedBlocksCheck;
use Tbtop\Cms\Health\HealthCheckRunner;
use Tbtop\Cms\Health\HealthStatus;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\TypedAliasBlock;
use Tbtop\Cms\Tests\Fixtures\BlockTypes\UntypedBlock;

it('passes when every registered block has a typed @return array{...}', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [TypedAliasBlock::class]);

    $report = new HealthCheckRunner;
    $result = collect($report->run()->results)->firstWhere('checkId', 'untyped_blocks');

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('warns, never fails, naming the untyped block kinds', function (): void {
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [UntypedBlock::class]);

    $report = new HealthCheckRunner;
    $result = collect($report->run()->results)->firstWhere('checkId', 'untyped_blocks');

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->messageReplace)->toBe(['kinds' => 'Untyped']);
});

it('surfaces the warning through cms:doctor without failing the command', function (): void {
    // Controlled check list, not the full defaultChecks(): a bare Testbench
    // app also fails unrelated real checks (see DoctorCommandTest.php).
    config()->set('tbtop-cms.blocks.types', []);
    BlockRegistry::extend(fn (): array => [UntypedBlock::class]);
    $this->app->instance(HealthCheckRunner::class, new HealthCheckRunner([app(UntypedBlocksCheck::class)]));

    $this->artisan('cms:doctor')
        ->expectsOutputToContain(__('tbtop-cms::health.checks.untyped_blocks.label'))
        ->assertExitCode(0);
});
