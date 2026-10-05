<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page;

use Illuminate\Support\Str;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Page\Models\Page;

/**
 * The rows a page type owns inside the page's content envelope.
 *
 * An owned row carries `owner` = the type key. Rows owned by the page's
 * CURRENT type show in the editor and on the site like any other block;
 * rows owned by another type are dormant: a page that changed type keeps
 * them untouched, so changing back finds its data in place. Rows without
 * an owner (editor-added and shared blocks) ignore the type entirely.
 */
final class TypeBlocks
{
    public function __construct(private readonly PageTypeRegistry $types) {}

    /**
     * Adds the rows the page's type declares and the page does not hold
     * yet (first save, or a type switch to one it never had). Existing rows
     * — visible or dormant — are left as they are.
     *
     * A content row added to a page that already exists starts inactive, the
     * same rule StarterBlockSync follows: an empty block must not go live on
     * a published page with the next save. General rows are fields, not
     * output, and are always active — an inactive one would not resolve.
     */
    public function ensure(Page $page): void
    {
        $declared = $this->declared($page->type);
        if ($declared === []) {
            return;
        }

        $content = BlockEnvelope::blocksOf($page->content);
        $present = [];
        foreach ($content as $row) {
            if (self::ownerOf($row) === $page->type) {
                $present[(string) $row['kind']] = true;
            }
        }

        $sort = self::maxSort($content);
        $isChanged = false;
        foreach ($declared as $block) {
            if (isset($present[$block->kind])) {
                continue;
            }
            $content[] = ['id' => (string) Str::ulid(), 'kind' => $block->kind, 'active' => $block->isGeneral || ! $page->exists, 'sort' => ++$sort, 'owner' => $page->type, 'data' => []];
            $isChanged = true;
        }

        if ($isChanged) {
            $page->content = $content;
        }
    }

    /** @return list<array<string, mixed>> rows without an owner plus rows owned by $type */
    public static function visible(mixed $content, string $type): array
    {
        return array_values(array_filter(
            BlockEnvelope::blocksOf($content),
            static fn (array $row): bool => self::ownerOf($row) === null || self::ownerOf($row) === $type,
        ));
    }

    /** @return array<string, int> owner type => dormant row count, for owners other than $type */
    public static function dormant(mixed $content, string $type): array
    {
        $counts = [];
        foreach (BlockEnvelope::blocksOf($content) as $row) {
            $owner = self::ownerOf($row);
            if ($owner !== null && $owner !== $type) {
                $counts[$owner] = ($counts[$owner] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public static function withoutDormant(mixed $content, string $type): array
    {
        return self::visible($content, $type);
    }

    /** @param  array<string, mixed>  $row */
    public static function ownerOf(array $row): ?string
    {
        $owner = $row['owner'] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    /**
     * True for an owned row whose type declares its kind as a general block.
     *
     * @param  array<string, mixed>  $row
     */
    public function isGeneral(array $row): bool
    {
        $owner = self::ownerOf($row);
        if ($owner === null) {
            return false;
        }

        foreach ($this->declared($owner) as $block) {
            if ($block->isGeneral && $block->kind === $row['kind']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kinds some registered type declares as general. The role itself is per
     * type (see isGeneral()); this list only tells the registry emitter which
     * kinds may be fields and nothing else.
     *
     * @return list<string>
     */
    public function generalKinds(): array
    {
        $kinds = [];
        foreach ($this->types->all() as $type) {
            foreach ($type->blocks() as $block) {
                if ($block->isGeneral) {
                    $kinds[$block->kind] = true;
                }
            }
        }

        return array_keys($kinds);
    }

    /** @return list<TypeBlock> empty for an unregistered type */
    public function declared(string $type): array
    {
        return $this->types->has($type) ? $this->types->get($type)->blocks() : [];
    }

    /** @param  list<array<string, mixed>>  $content */
    private static function maxSort(array $content): int
    {
        $max = -1;
        foreach ($content as $index => $row) {
            $max = max($max, is_int($row['sort'] ?? null) ? $row['sort'] : $index);
        }

        return $max;
    }
}
