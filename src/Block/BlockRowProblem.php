<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

use Tbtop\Cms\SharedBlock\SharedBlocks;

/**
 * Why a stored content row cannot render. The one rule the resolver (which
 * skips such a row) and the admin's problem-blocks list (which shows it to
 * the editor) both read, so a row the site skips for its stored state is
 * always listed in the editor. A block whose resolve() throws is out of
 * scope: that is a developer bug, logged as `block.resolve_failed` and
 * skipped without an editor listing. The two shared cases are exactly the
 * rows SharedBlocks::dataOf() returns null for.
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
