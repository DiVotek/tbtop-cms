<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Form;

use Tbtop\Cms\Block\BlockRegistry;
use Tbtop\Cms\Block\HandlesFormSubmission;
use Tbtop\Cms\Block\ResolvedBlock;
use Tbtop\Cms\Page\Models\Page;

/**
 * Injects a self-contained submit URL into each form block's props. json-render
 * hands a component only its props (no element id), so the frontend form learns
 * where to POST from `props.action` rather than reconstructing the endpoint.
 */
class FormActionBinder
{
    public function __construct(private readonly BlockRegistry $registry) {}

    /**
     * @param  list<ResolvedBlock>  $blocks
     * @return list<ResolvedBlock>
     */
    public function bind(array $blocks, Page $page): array
    {
        return array_map(fn (ResolvedBlock $block): ResolvedBlock => $this->bindOne($block, $page), $blocks);
    }

    private function bindOne(ResolvedBlock $block, Page $page): ResolvedBlock
    {
        if (! $this->registry->has($block->kind) || ! $this->registry->get($block->kind) instanceof HandlesFormSubmission) {
            return $block;
        }

        $action = route('tbtop-cms.form.submit', ['locale' => app()->getLocale(), 'page' => $page->getKey(), 'block' => $block->id]);

        return new ResolvedBlock($block->id, $block->kind, [...$block->props, 'action' => $action]);
    }
}
