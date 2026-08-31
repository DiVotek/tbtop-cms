<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Stringable;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Read-only "Source" card in PageEditSidebar for a source-backed page: the
 * type label, source_id the page was created from, and — when the type's
 * source() returns a Stringable — its display label. No link to an external
 * admin (dropped from the contract for this slice).
 */
final class PageEditSourceCard
{
    public static function build(S $s, Page $page): Node
    {
        $type = app(PageTypeRegistry::class)->get($page->type);
        $source = $type->source($page);

        return $s->section(['title' => __('tbtop-cms::pages.sections.source'), 'variant' => 'card'], [
            $s->displayKeyValue([
                __('tbtop-cms::pages.fields.type') => PageFormOptions::typeLabelFor($type->key(), $type->label()),
                __('tbtop-cms::pages.fields.source_id') => (string) $page->source_id,
                ...($source instanceof Stringable ? [__('tbtop-cms::pages.fields.source_label') => (string) $source] : []),
            ]),
        ]);
    }
}
