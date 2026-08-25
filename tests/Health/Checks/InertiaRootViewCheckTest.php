<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\InertiaRootViewCheck;
use Tbtop\Cms\Health\HealthStatus;

it('errors with a targeted message when the root view resolves to an unreadable path', function (): void {
    // A directory squatting on the view path: the view finder only asks
    // file_exists(), which is true for directories, so the view "resolves"
    // to a path that can never be read. The check must report that itself
    // rather than crash into the runner's generic error result.
    $path = resource_path('views/broken-root.blade.php');
    File::ensureDirectoryExists($path);
    config(['tbtop-cms.render.root_view' => 'broken-root']);

    try {
        $result = (new InertiaRootViewCheck)->run();

        expect($result->status)->toBe(HealthStatus::Error)
            ->and($result->message())->toBe(__('tbtop-cms::health.checks.inertia_root_view.unreadable', ['path' => $path]))
            ->and($result->hint())->not->toBeNull();
    } finally {
        File::deleteDirectory($path);
    }
});

it('errors when the configured root view does not exist at all', function (): void {
    config(['tbtop-cms.render.root_view' => 'no-such-root-view']);

    $result = (new InertiaRootViewCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->message())->toBe(__('tbtop-cms::health.checks.inertia_root_view.missing_view', ['view' => 'no-such-root-view']));
});

it('requires the cms head include before the Inertia head directive', function (string $directive, bool $includeFirst, HealthStatus $status): void {
    $path = resource_path('views/ordered-root.blade.php');
    $include = "@include('tbtop-cms::head')";
    File::put($path, $includeFirst ? "{$include}\n{$directive}" : "{$directive}\n{$include}");
    config(['tbtop-cms.render.root_view' => 'ordered-root']);

    try {
        expect((new InertiaRootViewCheck)->run()->status)->toBe($status);
    } finally {
        File::delete($path);
    }
})->with([
    'Inertia 2 correct order' => ['@inertiaHead', true, HealthStatus::Ok],
    'Inertia 2 reversed order' => ['@inertiaHead', false, HealthStatus::Error],
    'Inertia 3 correct order' => ['<x-inertia::head>', true, HealthStatus::Ok],
    'Inertia 3 reversed order' => ['<x-inertia::head>', false, HealthStatus::Error],
]);
