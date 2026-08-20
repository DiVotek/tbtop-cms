<?php

declare(strict_types=1);

use Tbtop\Cms\Health\Checks\SessionDriverCheck;
use Tbtop\Cms\Health\HealthStatus;

it('warns for a session driver the admin panel cannot rely on', function (string $driver): void {
    config(['session.driver' => $driver]);

    $result = (new SessionDriverCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->not->toBeNull();
})->with(['cookie', 'array']);

it('is ok for a server-side session driver', function (string $driver): void {
    config(['session.driver' => $driver]);

    $result = (new SessionDriverCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok)
        ->and($result->message())->toContain($driver);
})->with(['file', 'database', 'redis']);
