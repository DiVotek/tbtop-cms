<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\BlockRowProblem;
use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * The rows of a page's or layout slot's `content` that the site skips (see
 * BlockRowProblem) — inactive ones included, since such a row has no tab in
 * the editor and this list is the only place an editor meets it.
 */
final class ProblemBlocks
{
    /**
     * @param  list<array<string, mixed>>  $rows  well-formed rows in editor order (PageEditBlocks/LayoutEditBlocks::blocks())
     * @return list<array{id: string, kind: string, reason: BlockRowProblem, shared: int|null, data: array<string, mixed>}>
     */
    public static function of(array $rows): array
    {
        $registry = app(BlockRegistry::class);
        $sharedBlocks = app(SharedBlocks::class);
        $problems = [];

        foreach ($rows as $row) {
            $reason = BlockRowProblem::of($row, $registry, $sharedBlocks);
            if ($reason === null) {
                continue;
            }

            /** @var array<string, mixed> $data */
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];
            $problems[] = [
                'id' => (string) $row['id'],
                'kind' => (string) $row['kind'],
                'reason' => $reason,
                'shared' => SharedBlocks::refOf($row),
                'data' => $data,
            ];
        }

        return $problems;
    }

    /** @param  array<string, mixed>  $row */
    public static function isProblem(array $row): bool
    {
        return BlockRowProblem::of($row, app(BlockRegistry::class), app(SharedBlocks::class)) !== null;
    }
}
