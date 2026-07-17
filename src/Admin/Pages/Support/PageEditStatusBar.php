<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\DisplayValueBlock;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Frontend\PreviewLink;
use Tbtop\Cms\Language\Languages;
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

    public static function build(S $s, Page $page, ?ActionBuilder $revisions, ActionBuilder $viewOnSite, ActionBuilder $saveTop): Node
    {
        return $s->flex([
            self::statusBadge($s, $page),
            $s->unsavedIndicator(),
            $s->spacer(),
            ...self::timestamps($s, $page),
            ...($revisions !== null ? [$revisions] : []),
            $viewOnSite,
            ...self::previewLinkAction($s, $page),
            $saveTop,
        ], align: 'center', gap: 3, wrap: true, variant: 'card', class: 'text-xs');
    }

    /**
     * Draft-only: a published page has nothing to preview ahead of the live
     * site, so the action (and the modal it opens) simply doesn't exist for
     * it — mirrors canDelete()'s server-side conditional-build pattern in
     * PageEditPage rather than hiding a built action client-side.
     *
     * @return list<ActionBuilder>
     */
    private static function previewLinkAction(S $s, Page $page): array
    {
        if ($page->status !== PageStatus::Draft) {
            return [];
        }

        $close = $s->action('pagePreviewLinkClose')->label(__('tbtop-cms::pages.preview.close'))
            ->handle(static fn (ActionCtx $ctx): Effects => Effects::make()->closeModal());

        $field = $s->text('previewUrl')->label(__('tbtop-cms::pages.preview.field_label'))->meta('disabled', true);

        // The signed URL is only fetched via the action's query() below (which
        // runs when the modal opens) — this form carries no onSubmit because
        // there's nothing to save, only a value to display and copy.
        $form = $s->form('pagePreviewLinkForm', [
            $field,
            $s->displayText(__('tbtop-cms::pages.preview.helper', ['hours' => self::ttlHours()]))->variant('muted'),
            $s->flex([$s->spacer(), $close], justify: 'end'),
        ])->guardUnsaved(false);

        return [
            $s->action('pagePreviewLink')->label(__('tbtop-cms::pages.actions.preview_link'))
                ->icon('eye')
                ->modal(__('tbtop-cms::pages.actions.preview_link_title'), $form)
                ->query(static fn (ActionCtx $ctx): array => [
                    'previewUrl' => app(PreviewLink::class)->for($page, app(Languages::class)->defaultLocale()),
                ], needs: []),
        ];
    }

    private static function ttlHours(): int
    {
        return (int) config('tbtop-cms.preview.link_ttl_hours', 72);
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
