<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Models\Page;

/**
 * Hierarchical list-sort key: a materialized path built from (created_at, id)
 * pairs walked from the row up to its root, so siblings sort by created_at
 * ASC (oldest first) at every level and a child always lands directly after
 * its parent — e.g. Blog, BlogSub1, BlogSub2, News, NewsSub (root pages
 * ordered by creation, each subtree immediately following its root).
 *
 * Implementation: a fixed-depth (4 levels — matches CategoryType::maxDepth())
 * self-join chain up the parent_id chain via LEFT JOINs, concatenated
 * root-to-leaf. Missing ancestor levels contribute an empty string, which
 * sorts before any non-empty level value — so an ancestor's row always
 * precedes its descendants' rows under plain string ORDER BY. Each level's
 * contribution zero-pads `id` (created_at is already a fixed-width,
 * lexically-sortable "Y-m-d H:i:s" string) so string comparison agrees with
 * numeric/chronological comparison. Portable across sqlite (tests) and mysql
 * (production) — the only driver-specific piece is string concatenation
 * (`||` vs `CONCAT()`), isolated in concat().
 */
class PageSortKey
{
    private const DEPTH = 4;

    private const ID_PAD_WIDTH = 10;

    public const ALIAS = 'sort_key';

    /**
     * Joins the ancestor chain onto $query (aliased sort_key_p1..p4, p1 =
     * the row's immediate parent) and adds the concatenated sort key as
     * `self::ALIAS`.
     *
     * @param  Builder<Page>  $query
     * @return Builder<Page>
     */
    public static function apply(Builder $query): Builder
    {
        $table = (new Page)->getTable();

        $aliases = [];
        $parentColumn = "{$table}.parent_id";

        for ($level = 1; $level <= self::DEPTH; $level++) {
            $alias = "sort_key_p{$level}";
            $aliases[] = $alias;

            $query->leftJoin("{$table} as {$alias}", "{$alias}.id", '=', $parentColumn);
            $parentColumn = "{$alias}.parent_id";
        }

        // Root-to-leaf: deepest ancestor alias first, the row's own
        // contribution last.
        $levelExprs = [
            ...array_map(static fn (string $alias): string => self::levelExpr($alias), array_reverse($aliases)),
            self::levelExpr($table),
        ];

        // addSelect(['alias' => Expression]) drops the alias for a raw
        // Expression (Builder::addSelect only routes array-keyed aliasing
        // through selectSub() for queryable subqueries) — bake `AS alias`
        // into the raw SQL string instead.
        return $query->addSelect(DB::raw(self::concat($levelExprs) . ' as ' . self::ALIAS));
    }

    /**
     * One level's (created_at, id) contribution — empty string when the row
     * is absent (NULL id, i.e. shallower than this level).
     */
    private static function levelExpr(string $alias): string
    {
        $value = self::concat(["{$alias}.created_at", self::padId("{$alias}.id")]);

        return "CASE WHEN {$alias}.id IS NULL THEN '' ELSE {$value} END";
    }

    private static function padId(string $column): string
    {
        $width = self::ID_PAD_WIDTH;

        if (self::driver() === 'sqlite') {
            $zeros = str_repeat('0', $width);

            return "substr('{$zeros}' || {$column}, -{$width})";
        }

        if (self::driver() === 'pgsql') {
            return "LPAD(CAST({$column} AS TEXT), {$width}, '0')";
        }

        return "LPAD({$column}, {$width}, '0')";
    }

    /**
     * Portable string concatenation: sqlite's `||` operator vs MySQL's `CONCAT()`.
     *
     * @param  list<string>  $exprs
     */
    private static function concat(array $exprs): string
    {
        return self::driver() === 'sqlite'
            ? implode(' || ', $exprs)
            : 'CONCAT(' . implode(', ', $exprs) . ')';
    }

    private static function driver(): string
    {
        return DB::connection()->getDriverName();
    }
}
