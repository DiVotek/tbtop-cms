<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Throwable;

/** Environment info — two card-lists (versions, infrastructure) in a two-column grid. */
class SystemEnvironment
{
    /** @return list<Node> */
    public static function cards(S $s): array
    {
        return [self::versionsCard($s), self::infrastructureCard($s)];
    }

    private static function versionsCard(S $s): Node
    {
        $list = $s->list('dashboardSystemVersions')->items(fn (): array => [
            self::item(__('tbtop-cms::dashboard.system.php_version'), PHP_VERSION),
            self::item(__('tbtop-cms::dashboard.system.laravel_version'), app()->version()),
            self::item(__('tbtop-cms::dashboard.system.admin_version'), self::packageVersion('tbtop/admin')),
            self::item(__('tbtop-cms::dashboard.system.cms_version'), self::packageVersion('tbtop/cms')),
        ]);

        return $s->section([
            'title' => __('tbtop-cms::dashboard.system.versions_title'),
            'variant' => 'card',
        ], [$list]);
    }

    private static function infrastructureCard(S $s): Node
    {
        $list = $s->list('dashboardSystemInfrastructure')->items(fn (): array => [
            self::item(__('tbtop-cms::dashboard.system.db_driver'), self::safely(
                static fn (): string => (string) config('database.connections.' . config('database.default') . '.driver'),
            )),
            self::item(__('tbtop-cms::dashboard.system.db_size'), self::safely(self::databaseSize(...))),
            self::item(__('tbtop-cms::dashboard.system.cache_driver'), self::safely(
                static fn (): string => (string) config('cache.default'),
            )),
            self::item(__('tbtop-cms::dashboard.system.queue_driver'), self::safely(
                static fn (): string => (string) config('queue.default'),
            )),
        ]);

        return $s->section([
            'title' => __('tbtop-cms::dashboard.system.infrastructure_title'),
            'variant' => 'card',
        ], [$list]);
    }

    /** @return array{title: string, meta: string, color: string} */
    private static function item(string $title, string $meta): array
    {
        return ['title' => $title, 'meta' => $meta, 'color' => 'muted'];
    }

    private static function packageVersion(string $package): string
    {
        return self::safely(static fn (): string => (string) (InstalledVersions::getPrettyVersion($package) ?? 'n/a'));
    }

    private static function databaseSize(): string
    {
        $driver = (string) config('database.connections.' . config('database.default') . '.driver');

        return match ($driver) {
            'sqlite' => Number::fileSize((int) filesize((string) config('database.connections.sqlite.database'))),
            'mysql' => Number::fileSize(self::mysqlDatabaseSize()),
            default => __('tbtop-cms::dashboard.system.not_available'),
        };
    }

    private static function mysqlDatabaseSize(): int
    {
        $database = (string) config('database.connections.mysql.database');
        $row = DB::selectOne(
            'select sum(data_length + index_length) as bytes from information_schema.tables where table_schema = ?',
            [$database],
        );

        return (int) ($row->bytes ?? 0);
    }

    private static function safely(callable $resolver): string
    {
        try {
            return (string) $resolver();
        } catch (Throwable) {
            return __('tbtop-cms::dashboard.system.not_available');
        }
    }
}
