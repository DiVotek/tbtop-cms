<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Support\Str;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Page\Models\Page;

/**
 * Seeds a brand-new page's `content` from the nearest ancestor category's
 * starter blocks, at create time only — PageForm::save() calls this for the
 * $isCreate branch, where content is otherwise empty.
 *
 * A starter block is structure only — {id, kind}, no data (see PageEdit
 * StarterBlocks: it's built purely from BlockRegistry's kinds, never edited
 * with real field values). Turning it into a page's content block fills in
 * everything a fresh block needs: a page-local id, active=true (the page
 * just got created, usually as a draft, and the point is for its tab
 * structure to be visible immediately), empty data (there is nothing to
 * copy), and tpl_id pointing back at the starter element it came from — the
 * provenance StarterBlockSync later syncs against.
 *
 * Only 'article' and 'category' pages inherit anything; 'page' never does
 * (it has no starter-block slot to inherit from — see PageForm's starter
 * blocks section, category-only).
 *
 * The search walks up from the immediate parent (inclusive) looking for the
 * nearest category whose OWN starter_blocks[$type] is non-empty — a category
 * two levels up "wins" over one four levels up, and a category with no
 * starter of its own is transparent, not a dead end.
 *
 * A new category with no ancestor starter falls back to copying its
 * immediate parent's OWN content STRUCTURE (kind + order only, not the
 * parent's actual text) — "start this subcategory looking like its parent"
 * when nobody bothered to define an explicit subcategory starter. That
 * fallback copy carries no tpl_id: there is no starter-block source to sync
 * it against later.
 */
final class StarterBlocks
{
    /** @return list<array<string, mixed>> */
    public function contentFor(Page $newPage): array
    {
        if (! in_array($newPage->type, ['article', 'category'], true) || $newPage->parent_id === null) {
            return [];
        }

        $chain = array_reverse(AncestorChain::forPage($newPage)); // nearest-first

        foreach ($chain as $ancestor) {
            if ($ancestor->type !== 'category') {
                continue;
            }

            $starter = BlockEnvelope::starterOf($ancestor->starter_blocks, $newPage->type);
            if ($starter !== []) {
                return self::blocksFromStarter($starter);
            }
        }

        if ($newPage->type === 'category') {
            $parent = $chain[0] ?? null;
            if ($parent !== null && $parent->type === 'category') {
                return self::blocksFromParentStructure(BlockEnvelope::blocksOf($parent->content));
            }
        }

        return [];
    }

    /**
     * @param  list<array<string, mixed>>  $starter
     * @return list<array<string, mixed>>
     */
    private static function blocksFromStarter(array $starter): array
    {
        return array_map(
            static fn (array $element, int $index): array => [
                'id' => (string) Str::ulid(),
                'kind' => (string) ($element['kind'] ?? ''),
                'active' => true,
                'sort' => $index,
                'data' => [],
                'tpl_id' => $element['id'] ?? null,
            ],
            $starter,
            array_keys($starter),
        );
    }

    /**
     * Same shape as blocksFromStarter() minus tpl_id — a fallback copy has
     * no starter element to trace provenance back to.
     *
     * @param  list<array<string, mixed>>  $parentBlocks
     * @return list<array<string, mixed>>
     */
    private static function blocksFromParentStructure(array $parentBlocks): array
    {
        return array_map(
            static fn (array $block, int $index): array => [
                'id' => (string) Str::ulid(),
                'kind' => (string) ($block['kind'] ?? ''),
                'active' => true,
                'sort' => $index,
                'data' => [],
            ],
            $parentBlocks,
            array_keys($parentBlocks),
        );
    }
}
