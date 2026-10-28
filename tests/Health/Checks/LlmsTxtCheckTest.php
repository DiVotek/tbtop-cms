<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tbtop\Cms\Health\Checks\LlmsTxtCheck;
use Tbtop\Cms\Health\HealthStatus;

afterEach(function (): void {
    $path = public_path('llms.txt');
    if (is_file($path)) {
        unlink($path);
    }
});

it('warns when a static llms.txt shadows the dynamic route', function (): void {
    File::ensureDirectoryExists(public_path());
    file_put_contents(public_path('llms.txt'), "# Site\n");

    $result = (new LlmsTxtCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->not->toBeNull();
});

it('is ok when no static llms.txt exists', function (): void {
    expect((new LlmsTxtCheck)->run()->status)->toBe(HealthStatus::Ok);
});
