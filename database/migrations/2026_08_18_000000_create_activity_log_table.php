<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Support\Config as ActivitylogConfig;

return new class extends Migration
{
    /** tbtop_settings key recording that this migration created the activity table. */
    private const string OWNERSHIP_KEY = 'internal.activity_log_table_created_by_cms';

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

        // Ownership record: only a table this migration created is ours to
        // drop on rollback. Lives in tbtop_settings (created earlier in this
        // package's migration order) because the migrations table has no
        // room for per-migration state.
        DB::table('tbtop_settings')->updateOrInsert(
            ['key' => self::OWNERSHIP_KEY],
            ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Rollback drops the table only when the ownership record says this
     * migration created it. A pre-existing table (host-owned, another
     * package's, or a custom activity model's) was skipped by up() and has no
     * record, so it is left untouched whatever it holds — a host that wants
     * it gone drops it itself.
     */
    public function down(): void
    {
        $owned = DB::table('tbtop_settings')->where('key', self::OWNERSHIP_KEY)->exists();
        if (! $owned) {
            return;
        }

        $model = app(ActivitylogConfig::activityModel());
        Schema::connection($model->getConnectionName())->dropIfExists($model->getTable());
        DB::table('tbtop_settings')->where('key', self::OWNERSHIP_KEY)->delete();
    }
};
