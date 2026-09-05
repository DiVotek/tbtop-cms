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
        // drop on rollback, and only that exact table — the record names the
        // connection and table so a later activity_model change cannot point
        // the rollback at somebody else's table. Lives in tbtop_settings
        // (created earlier in this package's migration order) because the
        // migrations table has no room for per-migration state.
        DB::table('tbtop_settings')->updateOrInsert(
            ['key' => self::OWNERSHIP_KEY],
            [
                'value' => json_encode(['connection' => $connection, 'table' => $table], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Rollback drops exactly the table the ownership record names, and only
     * when that record exists. A pre-existing table (host-owned, another
     * package's, or a custom activity model's) was skipped by up() and has no
     * record, so it is left untouched whatever it holds — a host that wants
     * it gone drops it itself. The currently configured activity model is
     * deliberately not consulted here: it may have changed since migrate.
     */
    public function down(): void
    {
        $raw = DB::table('tbtop_settings')->where('key', self::OWNERSHIP_KEY)->value('value');
        if (! is_string($raw)) {
            return;
        }

        $owned = json_decode($raw, true);
        if (is_array($owned) && is_string($owned['table'] ?? null)) {
            $connection = is_string($owned['connection'] ?? null) ? $owned['connection'] : null;
            Schema::connection($connection)->dropIfExists($owned['table']);
        }

        DB::table('tbtop_settings')->where('key', self::OWNERSHIP_KEY)->delete();
    }
};
