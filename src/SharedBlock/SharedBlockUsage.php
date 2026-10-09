<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\Expression;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page;

/**
 * Which pages and layout slots reference each shared block. Read from
 * every container's envelope rather than a join table: the pointer lives
 * inside the JSON content, and a page or layout save is the only thing
 * that changes it. Layouts count because the resolver honours a pointer
 * there too — a shared block a slot places must not look deletable.
 * Trashed pages count: a restore brings their pointers back. Old
 * revisions do not — restoring one onto a deleted block shows the
 * placeholder.
 *
 * On SQLite and MySQL the database answers from the JSON itself; any
 * other driver scans the content in PHP, since no suite runs a branch
 * for it.
 */
final class SharedBlockUsage
{
    /**
     * Placement counts only, for screens that show a number and never the
     * containers (the shared-block cards, the page editor's add-block
     * chooser on every render).
     *
     * @return array<int, int> container count by shared block id
     */
    public static function counts(): array
    {
        $counts = [];
        foreach ([Page::withTrashed(), Layout::query()] as $query) {
            $placements = self::placements($query);
            $perRef = $placements === null
                ? self::scanCounts($query)
                : $placements->newQuery()->fromSub($placements, 'placements')
                    ->selectRaw('ref, count(distinct container) as n')
                    ->groupBy('ref')
                    ->pluck('n', 'ref')
                    ->all();

            foreach ($perRef as $ref => $n) {
                $counts[(int) $ref] = ($counts[(int) $ref] ?? 0) + (int) $n;
            }
        }

        return $counts;
    }

    /** @return list<Page|Layout> pages in sort order, then layouts by slot */
    public static function using(int $id): array
    {
        return [
            ...self::containersPlacing(Page::withTrashed()->orderBy('sort_order')->orderBy('id'), $id),
            ...self::containersPlacing(Layout::query()->orderBy('slot'), $id),
        ];
    }

    /** @return list<int> distinct shared ids a content list points at */
    public static function refsIn(mixed $content): array
    {
        $refs = [];
        foreach (is_array($content) ? $content : [] as $block) {
            $ref = SharedBlocks::refOf($block);
            if ($ref !== null && ! in_array($ref, $refs, true)) {
                $refs[] = $ref;
            }
        }

        return $refs;
    }

    /**
     * @template TContainer of Page|Layout
     *
     * @param  Builder<TContainer>  $query
     * @return list<TContainer>
     */
    private static function containersPlacing(Builder $query, int $id): array
    {
        $placements = self::placements($query);
        if ($placements === null) {
            return array_values($query->get()->filter(
                static fn (Page | Layout $container): bool => in_array($id, self::refsIn($container->content), true),
            )->all());
        }

        $keys = $placements->newQuery()->fromSub($placements, 'placements')
            ->where('ref', $id)->distinct()->pluck('container')->all();

        return $keys === [] ? [] : array_values($query->whereKey($keys)->get()->all());
    }

    /**
     * @param  Builder<Page>|Builder<Layout>  $query
     * @return array<int, int>
     */
    private static function scanCounts(Builder $query): array
    {
        $counts = [];
        foreach ($query->pluck('content') as $content) {
            foreach (self::refsIn($content) as $ref) {
                $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /**
     * One row per top-level content row that is a pointer, as
     * (container, ref). The predicate is SharedBlocks::refOf in SQL: an
     * integer, or a non-empty string of ASCII digits — so `"7a"`, `""`,
     * `"-7"`, `7.5` and `true` are not pointers, and `"007"` is 7. Only
     * top-level rows are read, so a number inside block data never counts.
     *
     * @param  Builder<covariant Page|Layout>  $query
     */
    private static function placements(Builder $query): ?QueryBuilder
    {
        $base = $query->clone()->reorder()->toBase();
        $grammar = $base->getGrammar();
        $model = $query->getModel();
        $content = $grammar->wrap($model->qualifyColumn('content'));

        $sql = match ($model->getConnection()->getDriverName()) {
            // json_type/json_extract read the original column at the row's
            // own path: `j.value` is raw text for a scalar row, which the
            // json functions would reject as malformed.
            'sqlite' => (static function () use ($content): array {
                $type = "json_type({$content}, j.fullkey || '.shared')";
                $value = "json_extract({$content}, j.fullkey || '.shared')";

                return [
                    "json_each({$content}) as j",
                    "{$type} = 'integer' or ({$type} = 'text' and {$value} <> '' and {$value} not glob '*[^0-9]*')",
                    "cast({$value} as integer)",
                ];
            })(),
            // A JSON column, not varchar, keeps the type: int 7 and "7" both
            // count while 7.5 or true do not. json_table must be joined —
            // inside a correlated exists it returns no rows on MySQL 8.
            'mysql' => [
                "json_table({$content}, '\$[*]' columns (shared json path '\$.shared')) as j",
                "json_type(j.shared) = 'INTEGER' or (json_type(j.shared) = 'STRING' and json_unquote(j.shared) regexp '^[0-9]+\$')",
                'cast(json_unquote(j.shared) as signed)',
            ],
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        [$rows, $isPointer, $ref] = $sql;

        return $base->crossJoin(new Expression($rows))
            ->selectRaw($grammar->wrap($model->getQualifiedKeyName()) . " as container, {$ref} as ref")
            ->whereRaw("({$isPointer})");
    }
}
