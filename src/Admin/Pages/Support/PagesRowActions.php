<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use DomainException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\ReplicateAction;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Audit\AuditContext;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Events\PageRestored;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Support\PageDuplicator;

/**
 * The pages list's row actions: view on site and duplicate for live rows,
 * restore and force delete for trashed ones.
 */
final class PagesRowActions
{
    /** @return list<ActionBuilder> */
    public static function all(S $s): array
    {
        return [
            self::viewOnSiteAction($s),
            self::duplicateAction($s),
            self::restoreAction($s),
            self::forceDeleteAction($s),
        ];
    }

    private static function viewOnSiteAction(S $s): ActionBuilder
    {
        // label('') → icon-only; the tooltip carries the accessible name.
        return $s->action('pageView')->label('')->link()->icon('external-link')
            ->tooltip(__('tbtop-cms::pages.actions.view_on_site'))
            ->url('/{path}')->openUrlInNewTab()
            // A trashed page has no live path (its slugs were removed by
            // PageObserver::deleting()) — the link would 404.
            ->hiddenIf('deleted_at', 'not empty');
    }

    private static function duplicateAction(S $s): ActionBuilder
    {
        return ReplicateAction::make($s, self::duplicate(...), name: 'pageDuplicate')
            ->label(__('tbtop-cms::pages.actions.duplicate'))
            // Only active rows can be duplicated — a trashed page has no live
            // slug/children guarantees to clone from meaningfully, and
            // restore/forceDelete already own the trashed-row action slot.
            ->hiddenIf('deleted_at', 'not empty');
    }

    private static function duplicate(ActionCtx $ctx): Effects
    {
        $page = Page::findOrFail((int) $ctx->key());

        app(PageDuplicator::class)->duplicate($page);

        return Effects::make()->notify(__('tbtop-cms::pages.duplicate.notify'))->refreshTable();
    }

    private static function restoreAction(S $s): ActionBuilder
    {
        return $s->action('pageRestore')->label(__('tbtop-cms::pages.actions.restore'))->color('gray')
            ->handle(self::restore(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private static function restore(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) $ctx->key());

        try {
            $page->restore();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageRestored($page));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.restored'))->refreshTable();
    }

    private static function forceDeleteAction(S $s): ActionBuilder
    {
        return $s->action('pageForceDelete')->label(__('tbtop-cms::pages.actions.force_delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.actions.force_delete_title'), __('tbtop-cms::pages.actions.force_delete_confirm'))
            ->handle(self::forceDelete(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private static function forceDelete(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) $ctx->key());

        try {
            app(AuditContext::class)->during(
                'force_deleted',
                [],
                static fn (): ?bool => $page->forceDelete(),
            );
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageDeleted($page, forced: true));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.force_deleted'))->refreshTable();
    }
}
