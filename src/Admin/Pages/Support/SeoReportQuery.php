<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same shape as HealthReportQuery, one table over: SeoAudit's rows are PHP
 * array output, not an Eloquent model, so the DSL table (which needs a real
 * query Builder — see HealthReportQuery's docblock) is fed through the same
 * throwaway sqlite :memory: connection rather than a hand-rolled fake
 * Builder.
 *
 * Takes the already-computed issue list rather than running SeoAudit itself
 * (unlike HealthReportQuery, which owns its own run of HealthCheckRunner):
 * SeoReportPage also needs the same list for its summary alert, and this
 * class's caller already has it — running the audit a second time here would
 * double the exact per-page-load cost the "one query, no per-page resolves"
 * design in SeoAudit exists to keep low.
 */
final class SeoReportQuery
{
    private const string CONNECTION = 'tbtop-cms-seo-report';

    private const string TABLE = 'seo_report_issues';

    /** Same driver dependency as HealthReportQuery — see its supported() docblock. */
    public static function supported(): bool
    {
        return extension_loaded('pdo_sqlite');
    }

    /** @param  list<array{severity: string, issue: string, page: string, locale: string, detail: string, sortKey: string}>  $issues */
    public static function build(array $issues): Builder
    {
        self::registerConnection();
        self::seed($issues);

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
     * Re-created every call: request-scoped :memory: database, no stale-row risk — see HealthReportQuery::seed().
     *
     * @param  list<array{severity: string, issue: string, page: string, locale: string, detail: string, sortKey: string}>  $issues
     */
    private static function seed(array $issues): void
    {
        $schema = Schema::connection(self::CONNECTION);
        $schema->dropIfExists(self::TABLE);
        $schema->create(self::TABLE, function ($table): void {
            $table->increments('id');
            $table->string('sort_key');
            $table->string('severity');
            $table->string('issue');
            $table->string('page');
            $table->string('locale');
            $table->text('detail');
        });

        $rows = array_map(static fn (array $issue): array => [
            'sort_key' => $issue['sortKey'],
            'severity' => $issue['severity'],
            'issue' => $issue['issue'],
            'page' => $issue['page'],
            'locale' => $issue['locale'],
            'detail' => $issue['detail'],
        ], $issues);

        if ($rows !== []) {
            DB::connection(self::CONNECTION)->table(self::TABLE)->insert($rows);
        }
    }
}
