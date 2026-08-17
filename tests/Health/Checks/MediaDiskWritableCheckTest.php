<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Tbtop\Cms\Health\Checks\MediaDiskWritableCheck;
use Tbtop\Cms\Health\HealthStatus;

it('is ok when the configured media disk accepts a write+delete probe', function (): void {
    Storage::fake('public');
    config(['tbtop-admin.media.disk' => 'public']);

    $result = (new MediaDiskWritableCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    // The probe file must not be left behind on a successful check.
    Storage::disk('public')->assertDirectoryEmpty('tbtop-cms-health-check');
});

it('warns when the probe file survives its delete', function (): void {
    // A write-only credential: put/exists behave, delete silently fails.
    // Filesystem fakes always honor delete, so the adapter is stubbed.
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->once()->andReturn(true);
    $disk->shouldReceive('exists')->twice()->andReturn(true, true);
    $disk->shouldReceive('delete')->once()->andReturn(false);
    Storage::shouldReceive('disk')->with('write-only')->andReturn($disk);
    config(['tbtop-admin.media.disk' => 'write-only']);

    $result = (new MediaDiskWritableCheck)->run();

    expect($result->status)->toBe(HealthStatus::Warning)
        ->and($result->hint())->not->toBeNull();
});

it('errors when the configured disk does not exist', function (): void {
    config(['tbtop-admin.media.disk' => 'does-not-exist']);

    $result = (new MediaDiskWritableCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error)
        ->and($result->hint())->not->toBeNull();
});
