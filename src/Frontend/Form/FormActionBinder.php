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
 *
 * Also injects the honeypot field name (props.honeypotField) when one is
 * configured (`tbtop-cms.forms.honeypot_field`). The cms ships no frontend JS
 * of its own — the consuming site renders the form from this payload — so the
 * honeypot is a plain field-descriptor contract, not a widget: the frontend is
 * expected to render an extra input named `props.honeypotField`, visually
 * hidden from real visitors (e.g. `position: absolute; left: -9999px` or an
 * `aria-hidden` + off-screen wrapper — NOT `display:none`/`type=hidden`, which
 * some bots skip), with `tabindex="-1"` and `autocomplete="off"` so it's
 * unreachable by keyboard and not autofilled by password managers, and
 * submitted alongside the real fields. {@see FormSubmitController} silently
 * drops any submission that arrives with this field non-empty. Omitted from
 * props entirely when the config value is null/false.
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
        $honeypotField = config('tbtop-cms.forms.honeypot_field');

        $props = [...$block->props, 'action' => $action];

        if (is_string($honeypotField) && $honeypotField !== '') {
            $props['honeypotField'] = $honeypotField;
        }

        return new ResolvedBlock($block->id, $block->kind, $props, $block->error);
    }
}
