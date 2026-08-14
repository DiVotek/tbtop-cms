<?php

declare(strict_types=1);

use Tbtop\Cms\Health\Checks\PackageVersionsMatchCheck;
use Tbtop\Cms\Health\HealthStatus;

it('warns instead of crashing when the JS package cannot be found', function (): void {
    // The workbench fixture app has no node_modules/@tbtop/inertia-admin.
    $result = (new PackageVersionsMatchCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->not->toBeNull();
});
