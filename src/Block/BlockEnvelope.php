<?php

declare(strict_types=1);

namespace Tbtop\Cms\Block;

/**
 * Guards a raw `content`/`starter_blocks[type]` JSON value down to a clean
 * list of block entries — every element is at least an array, nothing else
 * is assumed about its shape. Shared by every caller that reads a block
 * envelope column without going through BlockContentResolver (which also
 * resolves each block for rendering): StarterBlocks, StarterBlockSync,
 * PageForm, PageEditStarterBlocks.
 */
final class BlockEnvelope
{
    /** @return list<array<string, mixed>> */
    public static function blocksOf(mixed $content): array
    {
        if (! is_array($content)) {
            return [];
        }

        return array_values(array_filter($content, static fn (mixed $block): bool => is_array($block)));
    }

    /**
     * A page's `starter_blocks[$type]` slot — same guard as blocksOf(), plus
     * the `starter_blocks` column itself may be null/malformed (fresh page,
     * or non-category type that never sets it).
     *
     * @return list<array<string, mixed>>
     */
    public static function starterOf(mixed $starterBlocks, string $type): array
    {
        return self::blocksOf(is_array($starterBlocks) ? ($starterBlocks[$type] ?? null) : null);
    }
}
