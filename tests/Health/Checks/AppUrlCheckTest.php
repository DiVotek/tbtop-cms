<?php

declare(strict_types=1);

use Tbtop\Cms\Health\Checks\AppUrlCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

/**
 * The production flag must not outlive the check: migrations from the next
 * test's RefreshDatabase would hit Laravel's "run in production?" prompt.
 */
function runAppUrlCheckInProduction(string $url): HealthCheckResult
{
    app()['env'] = 'production';
    config(['app.url' => $url]);

    try {
        return (new AppUrlCheck)->run();
    } finally {
        app()['env'] = 'testing';
    }
}

it('skips outside the production environment', function (): void {
    $result = (new AppUrlCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});

it('errors in production when APP_URL is empty or not an absolute http(s) URL', function (string $url): void {
    $result = runAppUrlCheckInProduction($url);

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->hint())->not->toBeNull();
})->with([
    'empty' => [''],
    'relative path' => ['/var/www/html'],
    'scheme-less host' => ['example.com'],
    'non-http scheme' => ['ftp://example.com'],
]);

it('warns in production for a localhost or plain-http APP_URL', function (string $url): void {
    $result = runAppUrlCheckInProduction($url);

    expect($result->status)->toBe(HealthStatus::Warning);
})->with([
    'localhost' => ['https://localhost'],
    'plain http' => ['http://example.com'],
]);

it('is ok in production for an absolute https URL', function (): void {
    $result = runAppUrlCheckInProduction('https://example.com');

    expect($result->status)->toBe(HealthStatus::Ok);
});
