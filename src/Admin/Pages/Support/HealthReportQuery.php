<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tbtop\Cms\Health\HealthCheckRunner;

/**
 * TableBuilder::query() must return a real Eloquent/Query Builder (see
 * Tbtop\Admin\Http\TableQuery::run) — the DSL table never learned an
 * in-memory row source, and every existing admin page is backed by a real
 * database table. The health report has neither: its "rows" are the output
 * of running ten PHP objects. Rather than inventing a parallel object that
 * only fakes the handful of Builder methods TableQuery happens to call today
 * (and silently breaks the moment it calls one more), this stands up a
 * throwaway sqlite :memory: connection per request, writes the already
 * localized report rows into it, and hands back a genuine query Builder —
 * satisfying the real contract and inheriting whatever table features
 * (sorting, search) get added later for free.
 */
final class HealthReportQuery
{
    private const string CONNECTION = 'tbtop-cms-health-check';

    private const string TABLE = 'health_checks';

    /**
     * The table rendering (not the checks themselves) needs the sqlite PDO
     * driver for the :memory: connection below. Near-universal — Laravel
     * ships with sqlite as its default database since 11 — but a stripped
     * MySQL-only PHP build can lack it, and the page must degrade to the
     * non-table fallback instead of fataling before any result renders.
     */
    public static function supported(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    public static function build(): Builder
    {
        self::registerConnection();
        self::seed();

        return DB::connection(self::CONNECTION)->table(self::TABLE);
    }

    private static function registerConnection(): void
    {
        config(['database.connections.' . self::CONNECTION => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]]);
    }

    /**
     * Re-created every call: this connection is request-scoped (a fresh
     * :memory: database per PHP process/request), so there is no persisted
     * schema to reuse and no stale-row risk to guard against.
     */
    private static function seed(): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->dropIfExists(self::TABLE);
        $schema->create(self::TABLE, function ($table): void {
            $table->string('check_id')->primary();
            $table->string('status');
            $table->string('label');
            $table->text('message');
            $table->text('hint')->nullable();
        });

        $report = app(HealthCheckRunner::class)->run();
        $rows = array_map(static fn ($result): array => [
            'check_id' => $result->checkId,
            'status' => $result->status->value,
            'label' => $result->label(),
            'message' => $result->message(),
            'hint' => $result->hint(),
        ], $report->results);

        DB::connection(self::CONNECTION)->table(self::TABLE)->insert($rows);
    }
}
