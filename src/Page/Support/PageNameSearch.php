<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Error;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use PDO;
use Pdo\Sqlite;
use Tbtop\Cms\Page\Models\Page;
use WeakMap;

/**
 * Narrows a page query to names containing a search term, ignoring case in
 * every script.
 *
 * A plain `name->en like ?` is case-sensitive on MySQL (the unquoted JSON
 * value carries utf8mb4_bin) and on SQLite folds ASCII only, so "cont" missed
 * "Contact" on MySQL and "кон" missed "Контакти" on both. Here both sides are
 * lower-cased: the term with mb_strtolower(), the column with the driver's
 * LOWER(). SQLite's LOWER() is ASCII-only too, so on SQLite the column goes
 * through `tbtop_lower`, an mb_strtolower() function registered on the
 * connection.
 */
final class PageNameSearch
{
    private const SQLITE_LOWER = 'tbtop_lower';

    /** @var WeakMap<PDO, true>|null connections already warned about, so a request logs once */
    private static ?WeakMap $warned = null;

    /**
     * @param  Builder<Page>  $query
     * @param  list<string>  $locales  a page matches when its name in any of them matches
     */
    public static function apply(Builder $query, string $term, array $locales): void
    {
        $connection = $query->getModel()->getConnection();
        $lower = self::lowerFunction($connection);
        $grammar = $query->getQuery()->getGrammar();
        $like = '%' . self::escapeLike(mb_strtolower($term)) . '%';

        $query->where(static function (Builder $q) use ($locales, $lower, $grammar, $like): void {
            foreach (array_values(array_unique($locales)) as $locale) {
                $column = $grammar->wrap('name->' . $locale);
                $q->orWhereRaw("{$lower}({$column}) like ? escape '!'", [$like]);
            }
        });
    }

    /**
     * The same case-insensitive match on one plain string column, e.g. an
     * entry's `title`.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    public static function applyToColumn(Builder $query, string $column, string $term): void
    {
        $lower = self::lowerFunction($query->getModel()->getConnection());
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        $query->whereRaw("{$lower}({$wrapped}) like ? escape '!'", ['%' . self::escapeLike(mb_strtolower($term)) . '%']);
    }

    /**
     * `%` and `_` typed into the search match themselves, not any text. `!` is
     * the escape character because a backslash would need driver-specific
     * quoting inside the SQL literal (MySQL reads it as an escape there).
     */
    private static function escapeLike(string $term): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term);
    }

    private static function lowerFunction(Connection $connection): string
    {
        if ($connection->getDriverName() !== 'sqlite') {
            return 'lower';
        }

        $pdos = [$connection->getPdo()];
        if ($connection->getReadPdo() !== $pdos[0]) {
            $pdos[] = $connection->getReadPdo();
        }

        foreach ($pdos as $pdo) {
            if (! self::registerSqliteLower($pdo)) {
                self::warnPlainLower($pdo);

                return 'lower';
            }
        }

        return self::SQLITE_LOWER;
    }

    /**
     * False when the connection takes no user function: some PHP 8.4 builds
     * hand Laravel a plain PDO for `sqlite:` that has neither createFunction()
     * nor sqliteCreateFunction(). Search then runs on SQLite's own lower()
     * instead of failing the request.
     */
    private static function registerSqliteLower(PDO $pdo): bool
    {
        $lower = static fn (mixed $value): ?string => $value === null ? null : mb_strtolower((string) $value);

        if ($pdo instanceof Sqlite) {
            return $pdo->createFunction(self::SQLITE_LOWER, $lower, 1, Sqlite::DETERMINISTIC);
        }

        try {
            return $pdo->sqliteCreateFunction(self::SQLITE_LOWER, $lower, 1, PDO::SQLITE_DETERMINISTIC);
        } catch (Error) {
            // "Call to undefined method": the method is resolved per driver at runtime.
            return false;
        }
    }

    private static function warnPlainLower(PDO $pdo): void
    {
        self::$warned ??= new WeakMap;
        if (isset(self::$warned[$pdo])) {
            return;
        }

        self::$warned[$pdo] = true;
        Log::warning('tbtop-cms: the SQLite connection takes no user functions, so page search falls back to SQLite lower(), which ignores case in ASCII only. Bind a connector that returns Pdo\\Sqlite to restore it.');
    }
}
