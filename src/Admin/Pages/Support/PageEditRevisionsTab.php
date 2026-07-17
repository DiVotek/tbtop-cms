<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageRevision;
use Tbtop\Cms\Page\Support\PageRevisionWriter;

/**
 * Builds the trailing "Revisions" tab on PageEditPage: a read-only,
 * newest-first list of content snapshots with a restore action on every row
 * except the newest (restoring the current state is a no-op — see
 * restoreAction()). Lives inside the pageEditor form tabs like the block
 * tabs (PageEditBlockTabs), not a standalone DSL table — a table is a
 * top-level page widget in this DSL and would need its own query/route
 * plumbing for what is, here, just one more read-only tab body.
 */
final class PageEditRevisionsTab
{
    /**
     * Whether the revisions feature is on at all — same keep-based switch
     * PageRevisionWriter uses. PageEditPage::tabs() checks this before
     * building the tab, so a disabled feature leaves no trace in the UI even
     * when old rows still exist in page_revisions.
     */
    public static function enabled(): bool
    {
        return (int) config('tbtop-cms.revisions.keep', 25) > 0;
    }

    /** @return array{label: string, body: mixed, badge?: string} */
    public static function build(S $s, Page $page): array
    {
        $revisions = self::query($page)->get();

        $tab = [
            'label' => __('tbtop-cms::pages.tabs_edit.revisions'),
            'body' => $revisions->isEmpty()
                ? $s->stack([$s->displayText(__('tbtop-cms::pages.revisions.empty'))->variant('muted')])
                : $s->stack(self::rows($s, $page, $revisions)),
        ];

        if ($revisions->isNotEmpty()) {
            $tab['badge'] = (string) $revisions->count();
        }

        return $tab;
    }

    /**
     * Query limited to the retention cap: a page can never have more live
     * revisions than that (PageRevisionWriter prunes past it on every
     * snapshot), so this mirrors what actually exists rather than being an
     * arbitrary display limit.
     *
     * @return Builder<PageRevision>
     */
    private static function query(Page $page): Builder
    {
        // enabled() gates build(), so keep is always > 0 here.
        $keep = (int) config('tbtop-cms.revisions.keep', 25);

        return PageRevision::query()
            ->where('page_id', $page->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($keep);
    }

    /**
     * @param  Collection<int, PageRevision>  $revisions
     * @return list<Node>
     */
    private static function rows(S $s, Page $page, Collection $revisions): array
    {
        $authors = self::authorNames($revisions);

        return $revisions->values()
            ->map(static fn (PageRevision $revision, int $index): Node => self::row($s, $page, $revision, $authors, $index === 0))
            ->all();
    }

    /** @param  Collection<int|string, Model>  $authors */
    private static function row(S $s, Page $page, PageRevision $revision, Collection $authors, bool $isNewest): Node
    {
        $meta = $s->flex([
            $s->displayValue($revision->created_at)->datetime(),
            $s->displayText(self::authorLabel($revision, $authors))->variant('muted'),
            $s->displayText(self::kindLabel($revision))->variant('muted'),
        ], gap: 4, wrap: true);

        return $s->flex([
            $s->stack([$meta]),
            $s->spacer(),
            $isNewest
                ? $s->displayValue(__('tbtop-cms::pages.revisions.current'))->badge([
                    __('tbtop-cms::pages.revisions.current') => 'success',
                ])
                : self::restoreAction($s, $page, $revision),
        ], align: 'center', gap: 3, variant: 'card');
    }

    private static function restoreAction(S $s, Page $page, PageRevision $revision): ActionBuilder
    {
        $id = $revision->getKey();

        return $s->action("pageRevisionRestore_{$id}")
            ->label(__('tbtop-cms::pages.revisions.restore'))
            ->confirm(
                __('tbtop-cms::pages.revisions.restore_title'),
                __('tbtop-cms::pages.revisions.restore_confirm'),
            )
            ->handle(fn (ActionCtx $ctx): Effects => self::handleRestore($page, $id));
    }

    private static function handleRestore(Page $page, int $revisionId): Effects
    {
        $revision = PageRevision::query()
            ->where('page_id', $page->getKey())
            ->findOrFail($revisionId);

        app(PageRevisionWriter::class)->restore($page, $revision);

        return Effects::make()->notify(__('tbtop-cms::pages.revisions.restored'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $page->getKey()]));
    }

    private static function kindLabel(PageRevision $revision): string
    {
        return __('tbtop-cms::pages.revisions.kinds.' . $revision->kind);
    }

    /**
     * One whereIn() query for every author across all rows, rather than one
     * query per row — the tab renders at most config('tbtop-cms.revisions.keep')
     * rows, but there's no reason to pay N queries for something a single
     * query answers.
     *
     * @param  Collection<int, PageRevision>  $revisions
     * @return Collection<int|string, Model> keyed by user id
     */
    private static function authorNames(Collection $revisions): Collection
    {
        $userIds = $revisions->pluck('user_id')->filter()->unique()->values();

        if ($userIds->isEmpty()) {
            /** @var Collection<int|string, Model> */
            return new Collection;
        }

        /** @var class-string<Model> $userModel */
        $userModel = config('tbtop-cms.admin.model', 'App\\Models\\User');

        return $userModel::query()->whereIn('id', $userIds)->get()->keyBy(
            static fn (Model $user): int | string => $user->getKey(),
        );
    }

    /** @param  Collection<int|string, Model>  $authors */
    private static function authorLabel(PageRevision $revision, Collection $authors): string
    {
        $user = $revision->user_id !== null ? $authors->get($revision->user_id) : null;

        if ($user === null) {
            return __('tbtop-cms::pages.revisions.author_unknown');
        }

        $name = isset($user->name) ? (string) $user->name : '';
        if ($name !== '') {
            return $name;
        }

        $email = isset($user->email) ? (string) $user->email : '';

        return $email !== '' ? $email : __('tbtop-cms::pages.revisions.author_unknown');
    }
}
