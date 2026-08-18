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
            'candidates' => 'resources/js/admin.tsx, resources/js/admin.jsx, resources/js/admin.ts, resources/js/app.tsx, resources/js/app.jsx, resources/js/app.ts',
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
                'candidates' => 'resources/js/admin.tsx, resources/js/admin.jsx, resources/js/admin.ts, resources/js/app.tsx, resources/js/app.jsx, resources/js/app.ts',
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

it('confirms the wiring when the host writes it with double quotes', function (): void {
    // Hosts format their JS to their own conventions, and the entry published
    // by admin:install is double-quoted. Matching the quoted literal would
    // report a correctly wired host as broken.
    File::ensureDirectoryExists(base_path('resources/js'));
    File::put(base_path('resources/js/admin.tsx'), <<<'TSX'
        import "@cms/admin/blocks";
        import { AdminPage } from "@tbtop/inertia-admin";
        if (name === "admin/page") { return AdminPage; }
        TSX);
    File::ensureDirectoryExists(base_path('resources/css'));
    File::put(base_path('resources/css/admin.css'), "@source '../../vendor/tbtop/cms/src/**/*.php';");

    try {
        expect((new JsWiringCheck)->run()->status)->toBe(HealthStatus::Ok);
    } finally {
        File::deleteDirectory(base_path('resources/js'));
        File::deleteDirectory(base_path('resources/css'));
    }
});

it('reads the admin entry rather than the app entry when both exist', function (): void {
    // admin:install publishes a dedicated admin entry to keep the panel bundle
    // out of the public frontend, so that is where the admin wiring lives —
    // an app entry left unwired by an older install must not fail the check.
    File::ensureDirectoryExists(base_path('resources/js'));
    File::put(base_path('resources/js/app.tsx'), '// the public frontend, deliberately free of admin wiring');
    File::put(base_path('resources/js/admin.tsx'), <<<'TSX'
        import "@cms/admin/blocks";
        import { AdminPage } from "@tbtop/inertia-admin";
        if (name === "admin/page") { return AdminPage; }
        TSX);
    File::ensureDirectoryExists(base_path('resources/css'));
    File::put(base_path('resources/css/admin.css'), "@source '../../vendor/tbtop/cms/src/**/*.php';");

    try {
        expect((new JsWiringCheck)->run()->status)->toBe(HealthStatus::Ok);
    } finally {
        File::deleteDirectory(base_path('resources/js'));
        File::deleteDirectory(base_path('resources/css'));
    }
});
