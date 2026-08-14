<?php

declare(strict_types=1);

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
