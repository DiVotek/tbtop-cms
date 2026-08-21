<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * spatie/laravel-activitylog does not load its own migrations — it
     * expects the host to publish them. The CMS registers its service
     * provider itself (see CmsServiceProvider) and writes to the log on every
     * page save, so the table has to exist without any host step. Guarded so
     * a host that already published spatie's migrations keeps its table
     * untouched; the shape is spatie's current one (event and batch_uuid
     * included, which upstream added in two later migrations).
     */
    public function up(): void
    {
        $connection = config('activitylog.database_connection');
        $table = (string) config('activitylog.table_name', 'activity_log');

        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->id();
            $table->string('log_name')->nullable()->index();
            $table->text('description');
            $table->nullableMorphs('subject');
            $table->string('event')->nullable();
            $table->nullableMorphs('causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally a no-op: up() skips creation when the host already
        // owns the shared activity log table, and rollback cannot determine
        // who created it without risking the host application's audit data.
    }
};
