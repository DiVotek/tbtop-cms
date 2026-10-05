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

    /**
     * The data a stored row carries: its own, or the shared block's when the
     * row points at one — the row's own `data` is then ignored even if
     * present. A kind mismatch is also "missing": the row decides the
     * component, and the shared block was retargeted. Every reader of block
     * data goes through here, so a reference means the same thing to the
     * renderer and to a form submission.
     *
     * @param  array<array-key, mixed>  $row
     * @return array<string, mixed>|null null when the referenced shared block does not exist
     */
    public function dataOf(array $row, string $kind): ?array
    {
        $ref = self::refOf($row);
        if ($ref === null) {
            /** @var array<string, mixed> $data */
            $data = is_array($row['data'] ?? null) ? $row['data'] : [];

            return $data;
        }

        $shared = $this->find($ref);

        return $shared === null || $shared->kind !== $kind ? null : $shared->data;
    }

    /** The shared id a stored row points at, or null for a row with its own data. */
    public static function refOf(mixed $row): ?int
    {
        $ref = is_array($row) ? ($row['shared'] ?? null) : null;

        return is_int($ref) || (is_string($ref) && ctype_digit($ref)) ? (int) $ref : null;
    }
}
