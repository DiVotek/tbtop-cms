<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('preserves a host-owned activity log table on rollback', function (): void {
    config(['activitylog.table_name' => 'host_activity_log']);

    Schema::create('host_activity_log', function (Blueprint $table): void {
        $table->id();
        $table->string('description');
    });
    DB::table('host_activity_log')->insert(['description' => 'host audit entry']);

    $migration = require __DIR__ . '/../../database/migrations/2026_08_18_000000_create_activity_log_table.php';

    $migration->up();
    $migration->down();

    expect(Schema::hasTable('host_activity_log'))->toBeTrue()
        ->and(DB::table('host_activity_log')->value('description'))->toBe('host audit entry');
});
