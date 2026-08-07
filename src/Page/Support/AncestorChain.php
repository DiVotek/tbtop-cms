<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Page\Models\Page;

class AncestorChain
{
    /**
     * Walk the parent chain via recursive CTE with a hard step cap.
     *
     * @return list<Page> ancestors root-first (self excluded)
     */
    public static function forPage(Page $page, int $maxSteps = 64): array
    {
        if ($page->parent_id === null) {
            return [];
        }

        // Table name is taken from the model, not user input, so
        // interpolating it into the SQL string is safe.
        $table = (new Page)->getTable();

        // Recursive CTE; the depth counter caps cycles, hydration dedupes.
        $rows = DB::select(
            <<<SQL
                WITH RECURSIVE chain AS (
                    SELECT *, 1 AS depth
                    FROM {$table}
                    WHERE id = ? AND deleted_at IS NULL
                    UNION ALL
                    SELECT p.*, c.depth + 1
                    FROM {$table} p
                    INNER JOIN chain c ON p.id = c.parent_id
                    WHERE c.depth < ? AND p.deleted_at IS NULL
                )
                SELECT * FROM chain
                SQL,
            [$page->parent_id, max(1, $maxSteps)],
        );

        $chain = [];
        $seen = [];

        foreach ($rows as $row) {
            $attrs = (array) $row;
            unset($attrs['depth']);

            /** @var Page $model */
            $model = Page::query()->getModel()->newFromBuilder($attrs);

            if (isset($seen[$model->id])) {
                continue;
            }
            $seen[$model->id] = true;

            $chain[] = $model;
        }

        return array_reverse($chain);
    }
}
