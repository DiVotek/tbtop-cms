<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;
use Throwable;

/**
 * Round-trips a small probe file through the configured media disk rather
 * than trusting the disk config to be reachable (a misconfigured S3 key, a
 * non-writable local path, or a disk name that doesn't exist in
 * config/filesystems.php all fail only when actually touched).
 */
final class MediaDiskWritableCheck implements HealthCheck
{
    public function id(): string
    {
        return 'media_disk_writable';
    }

    public function run(): HealthCheckResult
    {
        $disk = (string) config('tbtop-admin.media.disk', 'public');
        $probePath = 'tbtop-cms-health-check/' . Str::random(20) . '.txt';

        try {
            $storage = Storage::disk($disk);
            $storage->put($probePath, 'tbtop-cms health check');
            $written = $storage->exists($probePath);
            $storage->delete($probePath);
        } catch (Throwable $e) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.media_disk_writable.label',
                messageKey: 'tbtop-cms::health.checks.media_disk_writable.failed',
                messageReplace: ['disk' => $disk, 'error' => $e->getMessage()],
                hintKey: 'tbtop-cms::health.checks.media_disk_writable.hints.failed',
                hintReplace: ['disk' => $disk],
            );
        }

        if (! $written) {
            return new HealthCheckResult(
                checkId: $this->id(),
                status: HealthStatus::Error,
                labelKey: 'tbtop-cms::health.checks.media_disk_writable.label',
                messageKey: 'tbtop-cms::health.checks.media_disk_writable.failed',
                messageReplace: ['disk' => $disk, 'error' => __('tbtop-cms::health.checks.media_disk_writable.write_verified_false')],
                hintKey: 'tbtop-cms::health.checks.media_disk_writable.hints.failed',
                hintReplace: ['disk' => $disk],
            );
        }

        return new HealthCheckResult(
            checkId: $this->id(),
            status: HealthStatus::Ok,
            labelKey: 'tbtop-cms::health.checks.media_disk_writable.label',
            messageKey: 'tbtop-cms::health.checks.media_disk_writable.ok',
            messageReplace: ['disk' => $disk],
        );
    }
}
