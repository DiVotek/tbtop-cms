<?php

declare(strict_types=1);

namespace Tbtop\Cms\SharedBlock;

use Tbtop\Cms\SharedBlock\Models\SharedBlock;

/**
 * Per-request lookup for the resolver: a page with several references loads
 * the table once, and a layout slot resolved in the same request reuses it.
 * A save or delete in the same process clears it, so the editor's own
 * "save then re-render" sees the new content.
 */
final class SharedBlocks
{
    /** @var array<int, SharedBlock>|null */
    private ?array $byId = null;

    public function find(int $id): ?SharedBlock
    {
        $this->byId ??= SharedBlock::query()->get()->keyBy(fn (SharedBlock $block): int => (int) $block->getKey())->all();

        return $this->byId[$id] ?? null;
    }

    public function forget(): void
    {
        $this->byId = null;
    }

    /** The shared id a stored row points at, or null for a row with its own data. */
    public static function refOf(mixed $row): ?int
    {
        $ref = is_array($row) ? ($row['shared'] ?? null) : null;

        return is_int($ref) || (is_string($ref) && ctype_digit($ref)) ? (int) $ref : null;
    }
}
