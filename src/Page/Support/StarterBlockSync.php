<?php

declare(strict_types=1);

namespace Tbtop\Cms\Page\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Block\BlockEnvelope;
use Tbtop\Cms\Page\Models\Page;

/**
 * Force-applies a category's starter blocks (of one type, 'article' or
 * 'category') to every already-existing descendant page of that type —
 * the "Apply to existing …" action on PageEditStarterBlocks.
 *
 * Scope: exactly the pages that would have inherited from THIS category's
 * starter at creation time (StarterBlocks::contentFor() picks the nearest
 * ancestor starter, walking up from the immediate parent). Concretely: a
 * recursive walk over descendants collecting every page of $type, which
 * stops DESCENDING into a subcategory the moment that subcategory has its
 * own non-empty starter_blocks[$type] — everything under it inherits from
 * the subcategory, so the subcategory's starter governs it. The subcategory
 * ITSELF, however, stays in scope: its own starter only speaks for its
 * children, while the subcategory-as-a-page was shaped by this (ancestor)
 * category's starter — same rule that applied when it was created.
 *
 * A starter is structure only, so the sync is structural only (see
 * mergeBlocks()): a page block tagged with a tpl_id still present in the
 * starter is left completely alone — kind, data, active, position, all of
 * it, because the content on that block belongs to the page, not the
 * starter; a tpl_id no longer in the starter is dropped, taking whatever
 * content it held with it (the only destructive part of this sync); a page
 * block with no tpl_id (added directly on the page) is left untouched; a
 * starter element with no matching page block is inserted inactive (an
 * empty new block must not go live on a published page), positioned right
 * after the nearest earlier starter element that IS present on the page (or
 * at the very start if none is).
 */
final class StarterBlockSync
{
    /** @return int Number of pages the sync would touch — used for the confirm dialog's count, and by apply() itself. */
    public function affectedCount(Page $category, string $type): int
    {
        return count($this->scope($category, $type));
    }

    /**
     * Audited as one batch — without during()/batch() a bulk apply reads as
     * N unrelated page edits. The category row makes it greppable by subject.
     */
    public function apply(Page $category, string $type): int
    {
        $starter = BlockEnvelope::starterOf($category->starter_blocks, $type);
        $pages = $this->scope($category, $type);
        $audit = app(AuditContext::class);

        $context = [
            'category_id' => $category->getKey(),
            'starter_type' => $type,
            'affected_pages' => count($pages),
        ];

        DB::transaction(function () use ($audit, $context, $pages, $starter, $category): void {
            $audit->batch(function () use ($audit, $context, $pages, $starter, $category): void {
                $audit->during('starter_blocks_applied', $context, function () use ($pages, $starter): void {
                    foreach ($pages as $page) {
                        $page->content = self::mergeBlocks(BlockEnvelope::blocksOf($page->content), $starter);
                        $page->save();
                    }
                });

                $audit->log(
                    $category,
                    'starter_blocks_applied',
                    is_string($category->name) && $category->name !== ''
                        ? $category->name
                        : 'Page #' . $category->getKey(),
                    context: $context,
                );
            });
        });

        return count($pages);
    }

    /** @return list<Page> */
    private function scope(Page $category, string $type): array
    {
        $pages = [];
        $frontier = self::children($category->getKey());

        while ($frontier !== []) {
            $next = [];

            foreach ($frontier as $child) {
                if ($child->type === $type) {
                    $pages[] = $child;
                }

                if ($child->type !== 'category') {
                    continue;
                }

                // A subcategory that defined its own non-empty starter for
                // this type owns its subtree from here on — don't recurse
                // into it, matching StarterBlocks::contentFor()'s nearest-
                // ancestor-wins rule. The subcategory itself was already
                // collected above when $type is 'category': its own starter
                // governs its children, not the subcategory-as-a-page, which
                // was shaped by THIS starter at creation (see class docblock).
                if (BlockEnvelope::starterOf($child->starter_blocks, $type) !== []) {
                    continue;
                }

                $next = [...$next, ...self::children($child->getKey())];
            }

            $frontier = $next;
        }

        return $pages;
    }

    /** @return list<Page> */
    private static function children(int $parentId): array
    {
        return Page::query()->where('parent_id', $parentId)->get()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $pageBlocks
     * @param  list<array<string, mixed>>  $starter
     * @return list<array<string, mixed>>
     */
    private static function mergeBlocks(array $pageBlocks, array $starter): array
    {
        $starterIds = [];
        foreach ($starter as $element) {
            $starterIds[(string) ($element['id'] ?? '')] = true;
        }

        $merged = [];
        $seenTplIds = [];

        foreach ($pageBlocks as $pageBlock) {
            $tplId = $pageBlock['tpl_id'] ?? null;

            if ($tplId === null) {
                // Editor-added block: never touched, position preserved.
                $merged[] = $pageBlock;

                continue;
            }

            $tplId = (string) $tplId;

            if (! isset($starterIds[$tplId])) {
                // Starter dropped this element: remove the block (and its
                // content) from the page.
                continue;
            }

            $seenTplIds[$tplId] = true;
            // Structure only: the block round-trips completely unchanged —
            // its content belongs to the page, not the starter.
            $merged[] = $pageBlock;
        }

        return self::insertNewStarterBlocks($merged, $starter, $seenTplIds);
    }

    /**
     * Inserts every starter element the page doesn't have yet, as a fresh,
     * inactive, empty-data block, each right after the nearest earlier
     * starter element already present on the page (or at index 0 if none of
     * the starter elements before it made it onto the page). `sort` is
     * assigned from the final position so PageEditBlocks::blocks()/its tab
     * order matches the array order this method just built.
     *
     * @param  list<array<string, mixed>>  $merged
     * @param  list<array<string, mixed>>  $starter
     * @param  array<string, true>  $seenTplIds
     * @return list<array<string, mixed>>
     */
    private static function insertNewStarterBlocks(array $merged, array $starter, array $seenTplIds): array
    {
        $lastInsertedAfter = null;

        foreach ($starter as $element) {
            $tplId = (string) ($element['id'] ?? '');

            if (isset($seenTplIds[$tplId])) {
                $lastInsertedAfter = $tplId;

                continue;
            }

            $fresh = [
                'id' => (string) Str::ulid(),
                'kind' => (string) ($element['kind'] ?? ''),
                'active' => false,
                'data' => [],
                'tpl_id' => $tplId,
            ];
            $insertAt = $lastInsertedAfter === null
                ? 0
                : self::indexOfTplId($merged, $lastInsertedAfter) + 1;

            array_splice($merged, $insertAt, 0, [$fresh]);
            $lastInsertedAfter = $tplId;
        }

        return self::withSort($merged);
    }

    /** @param  list<array<string, mixed>>  $blocks */
    private static function indexOfTplId(array $blocks, string $tplId): int
    {
        foreach ($blocks as $index => $block) {
            if (($block['tpl_id'] ?? null) === $tplId) {
                return $index;
            }
        }

        return count($blocks) - 1;
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     * @return list<array<string, mixed>>
     */
    private static function withSort(array $blocks): array
    {
        return array_map(
            static fn (array $block, int $index): array => [...$block, 'sort' => $index],
            $blocks,
            array_keys($blocks),
        );
    }
}
