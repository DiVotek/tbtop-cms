<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * Why a stored content row cannot render. The one rule the resolver (which
 * skips such a row) and the admin's problem-blocks list (which shows it to
 * the editor) both read, so a row is never dropped from the site without
 * being listed in the editor. The two shared cases are exactly the rows
 * SharedBlocks::dataOf() returns null for.
 */
enum BlockRowProblem: string
{
    case Unregistered = 'unregistered';
    case SharedMissing = 'shared_missing';
    case SharedKindMismatch = 'shared_kind_mismatch';

    /** @param  array<array-key, mixed>  $row */
    public static function of(array $row, BlockRegistry $registry, SharedBlocks $sharedBlocks): ?self
    {
        $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
        if (! $registry->has($kind)) {
            return self::Unregistered;
        }

        $ref = SharedBlocks::refOf($row);
        if ($ref === null) {
            return null;
        }

        $shared = $sharedBlocks->find($ref);
        if ($shared === null) {
            return self::SharedMissing;
        }

        return $shared->kind !== $kind ? self::SharedKindMismatch : null;
    }
}
