<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Schedule;

/** @return list<string> Names of every scheduled event's summary. */
function scheduledEventSummaries(): array
{
    return array_map(
        static fn ($event): string => $event->getSummaryForDisplay(),
        app(Schedule::class)->events(),
    );
}

it('schedules the 404 prune command daily when auto_prune is enabled (the default)', function (): void {
    expect(scheduledEventSummaries())->toContain('tbtop-cms:not-found:prune');
});
