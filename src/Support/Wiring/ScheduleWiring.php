<?php

declare(strict_types=1);

namespace Tbtop\Cms\Support\Wiring;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Tbtop\Cms\Health\Checks\SchedulerHeartbeatCheck;
use Tbtop\Cms\NotFound\Commands\PruneNotFoundHitsCommand;

/**
 * Scheduled tasks the package registers on the host scheduler: the 404-log and
 * audit-log prunes and the scheduler heartbeat.
 *
 * Called from CmsServiceProvider::packageBooted().
 */
final class ScheduleWiring
{
    public static function wire(Application $app): void
    {
        self::wireNotFoundPruneSchedule($app);
        self::wireAuditPruneSchedule($app);
        self::wireSchedulerHeartbeat($app);
    }

    /**
     * Scheduled by the package, opt-out via config: unlike the scheduler
     * heartbeat (which must exist unconditionally, see below), a host that
     * prunes the 404 log some other way — or wants to keep every row — needs
     * a way to turn this off. Read with an inline default (published host
     * configs won't have the `not_found` key at all, see the config file's
     * comments), so silence here means "keep the package default", not
     * "disable it".
     */
    private static function wireNotFoundPruneSchedule(Application $app): void
    {
        if (! config('tbtop-cms.not_found.auto_prune', true)) {
            return;
        }

        $app->booted(function () use ($app): void {
            $schedule = $app->make(Schedule::class);

            $schedule->command(PruneNotFoundHitsCommand::class)->daily()
                ->name('tbtop-cms:not-found:prune');
        });
    }

    private static function wireAuditPruneSchedule(Application $app): void
    {
        if (! config('tbtop-cms.audit.auto_prune', true)) {
            return;
        }

        $app->booted(function () use ($app): void {
            $days = max(1, (int) config('tbtop-cms.audit.retention_days', 365));
            $app->make(Schedule::class)
                ->command("activitylog:clean tbtop-cms --days={$days} --force")
                ->daily()
                ->name('tbtop-cms:audit:prune');
        });
    }

    /**
     * Writes a timestamp SchedulerHeartbeatCheck reads back — the only way
     * to detect "the host's cron never calls schedule:run" from inside the
     * app itself, since a dead scheduler produces no error, just silence
     * (see PruneNotFoundHitsCommand and any future scheduled command never
     * running). Registered directly on the Schedule singleton rather than
     * requiring host wiring: unlike cms:not-found:prune (an opt-in cadence
     * the host chooses), the heartbeat must exist unconditionally for the
     * health check to have anything to read.
     */
    private static function wireSchedulerHeartbeat(Application $app): void
    {
        $app->booted(function () use ($app): void {
            $schedule = $app->make(Schedule::class);

            $schedule->call(static function (): void {
                Cache::forever(SchedulerHeartbeatCheck::CACHE_KEY, now()->toIso8601String());
            })->everyMinute()->name('tbtop-cms:health:scheduler-heartbeat');
        });
    }
}
