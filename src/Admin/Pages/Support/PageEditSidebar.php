<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;

/**
 * The right sidebar column on PageEditPage: publication status, hierarchy
 * (type/parent), and manual sort order — split out of the "General" tab
 * into their own titled sections.
 */
final class PageEditSidebar
{
    public static function build(S $s): Node
    {
        return $s->stack([
            $s->section(['title' => __('tbtop-cms::pages.sections.publication'), 'variant' => 'card'], [
                $s->select('status')->label(__('tbtop-cms::pages.fields.status'))
                    ->required()->options(PageFormOptions::statuses()),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.structure'), 'variant' => 'card'], [
                $s->select('type')->label(__('tbtop-cms::pages.fields.type'))
                    ->required()->options(PageFormOptions::types()),
                $s->select('parent_id')->label(__('tbtop-cms::pages.fields.parent'))
                    ->options(PageFormOptions::parents()),
            ]),
            $s->section(['title' => __('tbtop-cms::pages.sections.ordering'), 'variant' => 'card'], [
                $s->number('sort_order')->label(__('tbtop-cms::pages.fields.sort_order')),
            ]),
        ]);
    }
}
