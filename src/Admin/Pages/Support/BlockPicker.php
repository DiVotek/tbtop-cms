<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Closure;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;

/**
 * The "+ Add block" body shared by the page, layout and starter-block
 * editors: one card per block — its label, its description and an Add
 * button — grouped by category.
 */
final class BlockPicker
{
    /**
     * @param  array<string, Block>  $blocks
     * @param  Closure(string): ActionBuilder  $addAction
     */
    public static function build(S $s, array $blocks, Closure $addAction): Node
    {
        $groups = self::groups($blocks);
        // A lone group needs no heading: there is nothing to tell it apart from.
        $isTitled = count($groups) > 1;
        $sections = [];

        foreach ($groups as $category => $group) {
            $cards = [];
            foreach ($group as $kind => $block) {
                $cards[] = self::card($s, $block, $addAction((string) $kind));
            }

            $grid = $s->grid(['cols' => ['md' => 2, 'xl' => 3], 'gap' => 3], $cards);
            $sections[] = $isTitled
                ? $s->section(['title' => self::title((string) $category), 'variant' => 'plain'], [$grid])
                : $grid;
        }

        return $s->stack($sections);
    }

    /** The block's label, or the raw kind for a block no longer registered. */
    public static function label(BlockRegistry $registry, string $kind): string
    {
        return $registry->has($kind) ? $registry->get($kind)->label() : $kind;
    }

    private static function card(S $s, Block $block, ActionBuilder $action): Node
    {
        $rows = [
            $s->flex([$s->displayText($block->label())->variant('subheading'), $s->spacer(), $action], align: 'center', gap: 2),
        ];
        if ($block->description() !== null) {
            $rows[] = $s->displayText($block->description())->variant('muted');
        }

        return $s->flex($rows, direction: 'col', gap: 2, variant: 'card');
    }

    /**
     * Categories in registration order, uncategorized blocks last.
     *
     * @param  array<string, Block>  $blocks
     * @return array<string, array<string, Block>>
     */
    private static function groups(array $blocks): array
    {
        $groups = [];
        $uncategorized = [];

        foreach ($blocks as $kind => $block) {
            $category = $block->category();
            if ($category === null || $category === '') {
                $uncategorized[$kind] = $block;

                continue;
            }
            $groups[$category][$kind] = $block;
        }

        if ($uncategorized !== []) {
            $groups[''] = $uncategorized;
        }

        return $groups;
    }

    private static function title(string $category): string
    {
        return $category === '' ? __('tbtop-cms::blocks_showcase.uncategorized') : $category;
    }
}
