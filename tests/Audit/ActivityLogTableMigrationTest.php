<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function activityLogMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_08_18_000000_create_activity_log_table.php';
}

it('rolls back by dropping an activity log table that holds no rows', function (): void {
    expect(Schema::hasTable('activity_log'))->toBeTrue();

    activityLogMigration()->down();

    expect(Schema::hasTable('activity_log'))->toBeFalse();
});

it('never drops an activity log table that holds history on rollback', function (): void {
    DB::table('activity_log')->insert([
        'log_name' => 'tbtop-cms',
        'description' => 'kept',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    activityLogMigration()->down();

    expect(Schema::hasTable('activity_log'))->toBeTrue()
        ->and(DB::table('activity_log')->count())->toBe(1);
});
