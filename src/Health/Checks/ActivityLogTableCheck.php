<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks;

use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Health\HealthCheck;
use Tbtop\Cms\Health\HealthCheckResult;
use Tbtop\Cms\Health\HealthStatus;

final class ActivityLogTableCheck implements HealthCheck
{
    public function id(): string
    {
        return 'activity_log_table';
    }

    public function run(): HealthCheckResult
    {
        $table = (string) config('activitylog.table_name', 'activity_log');
        $columns = ['event', 'properties', 'batch_uuid', 'subject_type', 'subject_id', 'causer_type', 'causer_id'];
        $isReady = Schema::hasTable($table)
            && collect($columns)->every(static fn (string $column): bool => Schema::hasColumn($table, $column));

        return new HealthCheckResult(
            checkId: $this->id(),
            status: $isReady ? HealthStatus::Ok : HealthStatus::Error,
            labelKey: 'tbtop-cms::health.checks.activity_log_table.label',
            messageKey: $isReady
                ? 'tbtop-cms::health.checks.activity_log_table.ok'
                : 'tbtop-cms::health.checks.activity_log_table.missing',
            hintKey: $isReady ? null : 'tbtop-cms::health.checks.activity_log_table.hint',
            messageReplace: ['table' => $table],
        );
    }
}
