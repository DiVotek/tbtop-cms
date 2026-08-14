<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\RobotsTxtCheck;
use Tbtop\Cms\Health\HealthStatus;

afterEach(function (): void {
    $path = public_path('robots.txt');
    if (is_file($path)) {
        unlink($path);
    }
});

it('errors when a static robots.txt shadows the dynamic route', function (): void {
    // The workbench skeleton has no public/ until `composer serve` builds it
    // — locally it usually exists, in CI it does not.
    File::ensureDirectoryExists(public_path());
    file_put_contents(public_path('robots.txt'), "User-agent: *\n");

    $result = (new RobotsTxtCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->hint())->not->toBeNull();
});

it('is ok when no static robots.txt exists', function (): void {
    $result = (new RobotsTxtCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});
