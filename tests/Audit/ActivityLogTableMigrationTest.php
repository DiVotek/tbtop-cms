<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

const ACTIVITY_LOG_OWNERSHIP_KEY = 'internal.activity_log_table_created_by_cms';

function activityLogMigration(): object
{
    return require __DIR__ . '/../../database/migrations/2026_08_18_000000_create_activity_log_table.php';
}

it('records ownership when it creates the activity log table', function (): void {
    expect(Schema::hasTable('activity_log'))->toBeTrue()
        ->and(DB::table('tbtop_settings')->where('key', ACTIVITY_LOG_OWNERSHIP_KEY)->exists())->toBeTrue();
});

it('rolls back a table it created, history included, and clears the ownership record', function (): void {
    DB::table('activity_log')->insert([
        'log_name' => 'tbtop-cms',
        'description' => 'ours',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    activityLogMigration()->down();

    expect(Schema::hasTable('activity_log'))->toBeFalse()
        ->and(DB::table('tbtop_settings')->where('key', ACTIVITY_LOG_OWNERSHIP_KEY)->exists())->toBeFalse();
});

it('leaves a table it did not create untouched on rollback', function (): void {
    // A host-owned table: present before this migration ran, so no record.
    DB::table('tbtop_settings')->where('key', ACTIVITY_LOG_OWNERSHIP_KEY)->delete();

    activityLogMigration()->down();

    expect(Schema::hasTable('activity_log'))->toBeTrue();
});

it('does not claim a pre-existing table when run again', function (): void {
    DB::table('tbtop_settings')->where('key', ACTIVITY_LOG_OWNERSHIP_KEY)->delete();

    activityLogMigration()->up();

    expect(Schema::hasTable('activity_log'))->toBeTrue()
        ->and(DB::table('tbtop_settings')->where('key', ACTIVITY_LOG_OWNERSHIP_KEY)->exists())->toBeFalse();
});
