<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\JsWiringCheck;
use Tbtop\Cms\Health\HealthStatus;

it('warns instead of crashing when none of the expected host files can be found', function (): void {
    // The bare Testbench skeleton app (base_path() during tests) has no
    // resources/js or resources/css at all — every heuristic hits the
    // "cannot find" branch, which must degrade to warning, never error or
    // an uncaught exception (see JsWiringCheck's class docblock).
    $result = (new JsWiringCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())
        ->toContain(__('tbtop-cms::health.checks.js_wiring.hints.entry_not_found', [
            'candidates' => 'resources/js/app.tsx, resources/js/app.jsx, resources/js/app.ts',
        ]))
        ->toContain(__('tbtop-cms::health.checks.js_wiring.hints.admin_page_not_found', [
            'candidates' => 'resources/js/pages/admin/page.tsx, resources/js/pages/admin/page.jsx',
        ]))
        ->toContain(__('tbtop-cms::health.checks.js_wiring.hints.css_not_found', [
            'candidates' => 'resources/css/admin.css, resources/css/app.css',
        ]));
});

it('treats a directory squatting on a candidate path as not found, not as a readable file', function (): void {
    // file_exists() is true for directories, so a naive exists() candidate
    // scan would try to File::get() a directory and crash the check.
    // The skeleton has no resources/js at all, so deleting the whole
    // directory restores the baseline the first test relies on.
    File::ensureDirectoryExists(base_path('resources/js/app.tsx'));

    try {
        $result = (new JsWiringCheck)->run();

        expect($result->status)->toBe(HealthStatus::Warning)
            ->and($result->hint())->toContain(__('tbtop-cms::health.checks.js_wiring.hints.entry_not_found', [
                'candidates' => 'resources/js/app.tsx, resources/js/app.jsx, resources/js/app.ts',
            ]));
    } finally {
        File::deleteDirectory(base_path('resources/js'));
    }
});

it('warns instead of erroring when the JS entry exists but cannot be read', function (): void {
    $path = base_path('resources/js/app.tsx');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, "import '@cms/admin/blocks'");
    chmod($path, 0000);

    try {
        $result = (new JsWiringCheck)->run();

        expect($result->status)->toBe(HealthStatus::Warning)
            ->and($result->hint())->toContain(__('tbtop-cms::health.checks.js_wiring.hints.unreadable', ['path' => $path]));
    } finally {
        chmod($path, 0644);
        File::deleteDirectory(base_path('resources/js'));
    }
})->skip(
    fn (): bool => function_exists('posix_geteuid') && posix_geteuid() === 0,
    'root reads files regardless of their permission bits',
);
