<?php

declare(strict_types=1);

namespace Tbtop\Cms\Health\Checks\Concerns;

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\BlockRowProblem;
use Tbtop\Cms\Layout\Models\Layout;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * Counts, per page (trashed included — a restore brings the row back) and
 * layout slot, the content rows the site skips for one of $problems — the
 * same BlockRowProblem rule the resolver and the editor's Problem blocks
 * list use. One column per table is read; the shared blocks load once.
 */
trait CountsProblemRows
{
    /**
     * @param  list<BlockRowProblem>  $problems
     * @return list<string> "page #3: 2", "layout header: 1"
     */
    private function problemRowCounts(array $problems): array
    {
        $registry = app(BlockRegistry::class);
        $shared = new SharedBlocks;
        $counts = [];

        foreach (Page::withTrashed()->orderBy('id')->pluck('content', 'id') as $id => $content) {
            $count = self::countIn($content, $problems, $registry, $shared);
            if ($count > 0) {
                $counts[] = "page #{$id}: {$count}";
            }
        }
        foreach (Layout::query()->orderBy('slot')->pluck('content', 'slot') as $slot => $content) {
            $count = self::countIn($content, $problems, $registry, $shared);
            if ($count > 0) {
                $counts[] = "layout {$slot}: {$count}";
            }
        }

        return $counts;
    }

    /** @param  list<BlockRowProblem>  $problems */
    private static function countIn(mixed $content, array $problems, BlockRegistry $registry, SharedBlocks $shared): int
    {
        $count = 0;
        foreach (is_array($content) ? $content : [] as $row) {
            if (is_array($row) && in_array(BlockRowProblem::of($row, $registry, $shared), $problems, true)) {
                $count++;
            }
        }

        return $count;
    }
}
