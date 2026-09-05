<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Support\Config as ActivitylogConfig;

return new class extends Migration
{
    /**
     * spatie/laravel-activitylog does not load its own migrations — it
     * expects the host to publish them. The CMS registers its service
     * provider itself (see CmsServiceProvider) and writes to the log on every
     * page save, so the table has to exist without any host step. Guarded so
     * a host that already published spatie's migrations keeps its table
     * untouched; the shape is spatie's v5 one (attribute_changes column,
     * batch_uuid removed — see UPGRADING.md).
     *
     * v5 dropped the activitylog.table_name / activitylog.database_connection
     * config keys: the table name and connection now live on the Activity
     * model itself (activitylog.activity_model, default
     * Spatie\Activitylog\Models\Activity, both hardcoded to 'activity_log'
     * on the default connection). A host wanting a different table/connection
     * points activity_model at its own subclass; we read that resolved
     * model's $table/getConnectionName() so this migration still lands in
     * the right place.
     */
    public function up(): void
    {
        $model = app(ActivitylogConfig::activityModel());
        $connection = $model->getConnectionName();
        $table = $model->getTable();

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
            $table->json('attribute_changes')->nullable();
            $table->json('properties')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $model = app(ActivitylogConfig::activityModel());

        Schema::connection($model->getConnectionName())->dropIfExists($model->getTable());
    }
};
