<?php

declare(strict_types=1);

namespace Tbtop\Cms\Audit\Concerns;

use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;
use Tbtop\Cms\Admin\Support\CurrentAdmin;
use Tbtop\Cms\Audit\AuditContext;

trait LogsCmsActivity
{
    use LogsActivity {
        shouldLogEvent as private shouldLogSpatieEvent;
    }

    /** @return list<string> */
    abstract protected function auditFields(): array;

    abstract protected function auditLabel(): string;

    /** @return list<string> */
    protected function auditMarkerFields(): array
    {
        return [];
    }

    /** @return list<string> */
    protected function auditBlockFields(): array
    {
        return [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('tbtop-cms')
            ->logOnly($this->auditFields())
            ->logOnlyDirty()
            ->dontLogIfAttributesChangedOnly(['updated_at'])
            ->dontSubmitEmptyLogs();
    }

    protected function shouldLogEvent(string $eventName): bool
    {
        return CurrentAdmin::canAccess() && $this->shouldLogSpatieEvent($eventName);
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        app(AuditContext::class)->enrich(
            $activity,
            $eventName,
            $this->auditLabel(),
            $this->auditMarkerFields(),
            $this->auditBlockFields(),
        );
    }
}
