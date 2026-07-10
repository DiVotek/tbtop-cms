<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\DisplayValueBlock;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Enums\PageStatus;
use Tbtop\Cms\Page\Models\Page;

/**
 * The status-bar card at the top of PageEditPage: status badge, updated/
 * created timestamps, view-on-site, and a top-of-form save button mirroring
 * the footer save.
 *
 * PageStatus currently has two cases (draft/published) — there is no
 * "archived" case in the enum, so the badge color map only covers what
 * exists. Adding a third status is a data/enum change out of scope here.
 */
final class PageEditStatusBar
{
    /** @var array<string, string> */
    private const STATUS_COLORS = [
        'draft' => 'warning',
        'published' => 'success',
    ];

    public static function build(S $s, Page $page, ActionBuilder $viewOnSite, ActionBuilder $saveTop): Node
    {
        return $s->flex([
            self::statusBadge($s, $page),
            $s->unsavedIndicator(),
            $s->spacer(),
            ...self::timestamps($s, $page),
            $viewOnSite,
            $saveTop,
        ], align: 'center', gap: 3, wrap: true, variant: 'card', class: 'text-xs');
    }

    private static function statusBadge(S $s, Page $page): DisplayValueBlock
    {
        $label = __('tbtop-cms::pages.status.' . $page->status->value);

        return $s->displayValue($label)->badge([$label => self::colorFor($page->status)]);
    }

    /**
     * Every PageStatus case must have an entry here — adding a case without
     * one is a compile-time array-key error, which is the point: a missing
     * badge color should fail loudly, not silently fall back to gray.
     */
    private static function colorFor(PageStatus $status): string
    {
        return self::STATUS_COLORS[$status->value];
    }

    /** @return list<mixed> */
    private static function timestamps(S $s, Page $page): array
    {
        $nodes = [
            $s->displayText(__('tbtop-cms::pages.status_bar.updated'))->variant('muted'),
            $s->displayValue($page->updated_at)->datetime(),
        ];

        if ($page->created_at !== null && ! $page->created_at->equalTo($page->updated_at)) {
            $nodes[] = $s->displayText(__('tbtop-cms::pages.status_bar.created'))->variant('muted');
            $nodes[] = $s->displayValue($page->created_at)->datetime();
        }

        return $nodes;
    }
}
