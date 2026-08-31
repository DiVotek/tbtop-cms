<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\AgentDocsPointerCheck;
use Tbtop\Cms\Health\HealthStatus;

afterEach(function (): void {
    foreach (['AGENTS.md', 'CLAUDE.md'] as $name) {
        $path = base_path($name);
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('warns when neither AGENTS.md nor CLAUDE.md exists', function (): void {
    $result = (new AgentDocsPointerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->toContain('vendor/tbtop/cms/docs/host/ai/README.md');
});

it('warns when CLAUDE.md exists but does not point at the host-builder guide', function (): void {
    File::put(base_path('CLAUDE.md'), '# Host notes' . PHP_EOL);

    $result = (new AgentDocsPointerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->toContain('vendor/tbtop/cms/docs/host/ai/README.md');
});

it('is ok when AGENTS.md contains the pointer to the host-builder guide', function (): void {
    File::put(base_path('AGENTS.md'), 'Building on tbtop/cms → read vendor/tbtop/cms/docs/host/ai/README.md first.');

    $result = (new AgentDocsPointerCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
});
