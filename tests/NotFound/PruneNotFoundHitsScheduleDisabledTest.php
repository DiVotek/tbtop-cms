<?php

declare(strict_types=1);

namespace Tbtop\Cms\Tests\NotFound;

use Illuminate\Console\Scheduling\Schedule;
use Tbtop\Cms\Tests\TestCase;

/**
 * auto_prune must be set before the app boots — CmsServiceProvider wires the
 * schedule entry from packageBooted(), same reason DashboardWithoutPagesRouteTest
 * overrides config in defineEnvironment() rather than in the test body.
 */
class PruneNotFoundHitsScheduleDisabledTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('tbtop-cms.not_found.auto_prune', false);
    }

    public function test_does_not_schedule_the_404_prune_command_when_auto_prune_is_disabled(): void
    {
        $summaries = array_map(
            static fn ($event): string => $event->getSummaryForDisplay(),
            app(Schedule::class)->events(),
        );

        $this->assertNotContains('tbtop-cms:not-found:prune', $summaries);
    }
}
