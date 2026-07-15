<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Color;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\BlockShowcase;
use Tbtop\Cms\Admin\Pages\Support\BlockShowcaseHtml;
use Tbtop\Cms\Admin\Pages\Support\BlockUsage;
use Tbtop\Cms\Block\Block;
use Tbtop\Cms\Block\BlockRegistry;

/**
 * Read-only showcase of the content blocks registered in the system. Blocks
 * belong to page content, not to themselves — this surface only demonstrates
 * which blocks exist and what fields each one carries. Nothing is editable:
 * each card shows a preview placeholder and opens a details modal on click.
 */
class BlocksPage extends AdminPage
{
    public static function path(): string
    {
        return 'blocks';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'design',
            'label' => __('tbtop-cms::nav.items.blocks'),
            'order' => 40,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::blocks_showcase.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::blocks_showcase.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.blocks')],
        ];
    }

    public function view(S $s): Node
    {
        $blocks = array_values(app(BlockRegistry::class)->all());

        if ($blocks === []) {
            return $s->stack([
                $s->displayAlert(__('tbtop-cms::blocks_showcase.empty')),
            ]);
        }

        // Which pages use each block, computed once for all cards.
        $usage = BlockUsage::index();
        $cards = array_map(
            fn (Block $block): Node => $this->card($s, $block, $usage[$block->kind()] ?? []),
            $blocks,
        );

        return $s->stack([
            $s->grid(['cols' => ['sm' => 2, 'md' => 3, 'lg' => 4], 'gap' => 4], $cards),
        ]);
    }

    /**
     * @param  list<array{name: string, url: string|null}>  $usage
     */
    private function card(S $s, Block $block, array $usage): Node
    {
        $info = BlockShowcase::describe($block);
        $category = $info['category'] ?? __('tbtop-cms::blocks_showcase.uncategorized');

        // A hand-built card (not the section 'card' variant) so the preview
        // sits flush against the border.
        return $s->stack([
            $s->displayHtml(BlockShowcaseHtml::placeholder($info['kind'], $info['label'])),
            $s->stack([
                $s->displayText($info['label'])->variant('subheading'),
                $s->displayValue($category)->badge([$category => Color::Info]),
                $s->flex([
                    $s->displayText(__('tbtop-cms::blocks_showcase.usage_count', ['count' => (string) count($usage)]))
                        ->variant('muted'),
                    $this->detailsAction($s, $info, $usage),
                ], justify: 'between', align: 'center'),
            ], ['class' => 'p-3 gap-2']),
        ], ['class' => 'rounded-lg border bg-card overflow-hidden gap-0']);
    }

    /**
     * @param  array{kind: string, label: string, description: string|null, category: string|null, fields: list<array{name: string, label: string, type: string, required: bool, translatable: bool}>}  $info
     * @param  list<array{name: string, url: string|null}>  $usage
     */
    private function detailsAction(S $s, array $info, array $usage): ActionBuilder
    {
        return $s->action('blockDetails' . $info['kind'])
            ->label(__('tbtop-cms::blocks_showcase.actions.details'))
            ->modal($info['label'], $this->detailBody($s, $info, $usage))
            ->modalWidth('2xl');
    }

    /**
     * @param  array{kind: string, label: string, description: string|null, category: string|null, fields: list<array{name: string, label: string, type: string, required: bool, translatable: bool}>}  $info
     * @param  list<array{name: string, url: string|null}>  $usage
     */
    private function detailBody(S $s, array $info, array $usage): Node
    {
        $children = [
            $s->displayHtml(BlockShowcaseHtml::placeholder($info['kind'], $info['label'], aspect: '21 / 9', markSize: '3rem')),
        ];

        if ($info['description'] !== null) {
            $children[] = $s->displayText($info['description'])->variant('muted');
        }

        $children[] = $s->displayKeyValue($this->meta($info));

        $children[] = $s->displayText(__('tbtop-cms::blocks_showcase.section.fields'))->variant('subheading');
        $children[] = $s->displayHtml(BlockShowcaseHtml::fieldsTable(
            $info['fields'],
            __('tbtop-cms::blocks_showcase.table.field'),
            __('tbtop-cms::blocks_showcase.table.type'),
            __('tbtop-cms::blocks_showcase.table.attributes'),
            __('tbtop-cms::blocks_showcase.field.required'),
            __('tbtop-cms::blocks_showcase.field.translatable'),
        ));

        $children[] = $s->displayText(__('tbtop-cms::blocks_showcase.section.usage'))->variant('subheading');
        $children[] = $s->displayHtml(BlockShowcaseHtml::usageTable(
            $usage,
            __('tbtop-cms::blocks_showcase.table.page'),
            __('tbtop-cms::blocks_showcase.usage_empty'),
        ));

        return $s->stack($children, ['class' => 'gap-4']);
    }

    /**
     * @param  array{kind: string, category: string|null, fields: list<mixed>}  $info
     * @return array<string, string>
     */
    private function meta(array $info): array
    {
        $meta = [__('tbtop-cms::blocks_showcase.meta.kind') => $info['kind']];
        if ($info['category'] !== null) {
            $meta[__('tbtop-cms::blocks_showcase.meta.category')] = $info['category'];
        }
        $meta[__('tbtop-cms::blocks_showcase.meta.fields')] = (string) count($info['fields']);

        return $meta;
    }
}
