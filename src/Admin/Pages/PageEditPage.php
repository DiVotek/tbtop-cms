<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use DomainException;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Cond;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageEditBlocks;
use Tbtop\Cms\Admin\Pages\Support\PageEditBlockTabs;
use Tbtop\Cms\Admin\Pages\Support\PageEditRevisionsAction;
use Tbtop\Cms\Admin\Pages\Support\PageEditSidebar;
use Tbtop\Cms\Admin\Pages\Support\PageEditStarterBlocks;
use Tbtop\Cms\Admin\Pages\Support\PageEditStatusBar;
use Tbtop\Cms\Admin\Pages\Support\PageEditTypeFields;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageLeafType;
use Tbtop\Cms\Admin\Pages\Support\ProblemBlocks;
use Tbtop\Cms\Admin\Pages\Support\ProblemBlocksAction;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Events\PageDeleted;
use Tbtop\Cms\Page\Exceptions\PageConflictException;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\PageTypeRegistry;

/**
 * Dedicated full-page editor for a single page record: a status bar (badge,
 * timestamps, view-on-site, top save), a two-column grid body (tabbed content
 * + per-block tabs on the left, publication/structure/ordering sections in
 * the right sidebar), and a footer save/delete row.
 *
 * The General/SEO/block groups are real S::tabs(): since tbtop/admin 0.2.19
 * the form walkers (RuleWalker, TranslatableRecord) recurse into tab bodies,
 * so fields inside tabs validate and reach $ctx->form like any other field.
 */
class PageEditPage extends AdminPage
{
    private ?Page $pageCache = null;

    public static function path(): string
    {
        return 'pages/{page}/edit';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return null;
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.pages');
    }

    #[\Override]
    public function title(): string
    {
        return $this->page()->getTranslation('name', $this->defaultLocale(), false) ?: ('#' . $this->page()->getKey());
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return $this->page()->pathFor($this->defaultLocale());
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.pages'), 'url' => route('tbtop.cms.pages-page')],
            ['label' => $this->title()],
        ];
    }

    /**
     * View-on-site and save both moved into the status bar at the top of
     * the form; the header no longer carries any actions.
     *
     * @return list<ActionBuilder|Node>
     */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [];
    }

    public function view(S $s): Node
    {
        $page = $this->page();
        $saveTop = $this->saveActions($s, $page, 'saveTop');
        $saveFooter = $this->saveActions($s, $page, 'save');

        $footer = EditFooter::build(
            $s,
            route('tbtop.cms.pages-page'),
            $saveFooter,
            $this->canDelete($page) ? $this->deleteAction($s) : null,
        );

        $revisions = PageEditRevisionsAction::enabled() ? PageEditRevisionsAction::build($s, $page) : null;
        $problemBlocks = $this->isSourceBacked($page) ? null : ProblemBlocksAction::build(
            $s,
            'page',
            ProblemBlocks::of(PageEditBlocks::blocks($page)),
            PageEditBlockTabs::skipsGate($page),
            $this->deleteProblemRow(...),
        );

        $form = $s->form('pageEditor', [
            // A real (client-invisible) field, not just a record() key: the
            // client's own form-state round-trip would carry lock_version
            // back untouched with no field at all (serializeFormData starts
            // from the full record bag and only transforms values for nodes
            // it can find by name — see PageForm::loadRow()'s docblock), but
            // FormSubmitController runs $request->validate($form->collectRules())
            // server-side, and RuleWalker only emits a rule for fields that
            // are actually present in the tree — an undeclared key is
            // silently dropped from the validated payload before it ever
            // reaches PageForm::save(). meta('hidden', true) keeps it out of
            // the rendered UI (see structure/meta.ts's isNodeHidden) while
            // still giving RuleWalker something to collect a ('nullable')
            // rule for.
            $s->number('lock_version')->meta('hidden', true),
            PageEditStatusBar::build($s, $page, $revisions, $this->viewOnSiteAction($s), $saveTop, $problemBlocks),
            $s->grid(['cols' => ['sm' => 1, 'lg' => 3], 'gap' => 6], [
                $s->stack([$s->tabs($this->tabs($s, $page))], ['colSpan' => ['sm' => 1, 'lg' => 2]]),
                PageEditSidebar::build($s, $page),
            ]),
            $footer,
        ])
            ->record([
                ...PageForm::loadRow($this->pageId()),
                ...PageEditTypeFields::recordValues($page),
                ...($this->isSourceBacked($page) ? [] : PageEditBlockTabs::recordValues($page)),
            ])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    /**
     * A source-backed page's content is owned by its type's template, not by
     * an editor: no per-block tabs, no starter blocks (which only apply to a
     * category type anyway), no "+ Add block" tab.
     *
     * @return list<array{label: string, body: mixed, badge?: string}>
     */
    private function tabs(S $s, Page $page): array
    {
        $isSourceBacked = $this->isSourceBacked($page);

        return [
            [
                'label' => __('tbtop-cms::pages.tabs_edit.general'),
                'body' => $s->stack([
                    ...PageForm::generalFields($s, ! $page->is_system),
                    ...PageEditTypeFields::build($s, $page),
                    ...($isSourceBacked ? [] : PageEditStarterBlocks::build($s, $page)),
                ]),
            ],
            ['label' => __('tbtop-cms::pages.tabs_edit.seo'), 'body' => $s->stack(PageForm::seoFields($s, $page))],
            ...($isSourceBacked ? [] : PageEditBlockTabs::build($s, $page)),
        ];
    }

    private function isSourceBacked(Page $page): bool
    {
        return app(PageTypeRegistry::class)->isSourceBacked($page->type);
    }

    /**
     * A save that would turn a page into an article or back (PageLeafType)
     * asks first. The admin's confirm text is fixed per action, so the button
     * is swapped by a form condition: the plain one while the type stays, the
     * confirming one while the type would change. Both footer twins carry
     * mod+s: the client binds a shortcut only while its action is mounted.
     *
     * @return list<ActionBuilder>
     */
    private function saveActions(S $s, Page $page, string $name): array
    {
        $isShortcut = $name === 'save';
        $action = $this->saveAction($s, $name, $isShortcut);
        $flip = $this->isSourceBacked($page) || $page->is_system ? null : PageLeafType::flip($page);
        if ($flip === null) {
            return [$action];
        }

        return [
            $action->hiddenIf($flip['when']),
            $this->saveAction($s, "{$name}LeafFlip", $isShortcut)
                ->confirm($flip['title'], $flip['description'])
                ->hiddenIf(Cond::not($flip['when'])),
        ];
    }

    private function saveAction(S $s, string $name, bool $isShortcut): ActionBuilder
    {
        $action = $s->action($name)->label(__('tbtop-cms::pages.actions.save'))->color('primary')->submit('pageEditor');

        return $isShortcut ? $action->keybinding('mod+s') : $action;
    }

    /**
     * A stale lock_version returns a notify only — no redirect effect. The
     * submit's own back() already delivers the winning record, so the form
     * resyncs (fresh lock_version, edits dropped as the message explains);
     * a redirect effect would revisit the page and unmount the toast.
     */
    private function save(ActionCtx $ctx): Effects
    {
        $page = $this->page();
        $rows = PageEditBlocks::toRows($ctx->form, $page);

        try {
            PageForm::save([...$ctx->form, 'content' => $rows], $this->pageId());
        } catch (PageConflictException) {
            return Effects::make()->notify(__('tbtop-cms::pages.conflict.message'), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.actions.saved'))->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $this->pageId()]),
        );
    }

    /** A stale form keeps the modal open with the conflict message; nothing is written. */
    private function deleteProblemRow(ActionCtx $ctx, string $id): Effects
    {
        if (! PageEditBlockTabs::removeRow($ctx->form, $this->page(), $id)) {
            return Effects::make()->haltModal(__('tbtop-cms::pages.conflict.message'));
        }

        return Effects::make()->closeModal()->notify(__('tbtop-cms::pages.problem_blocks.deleted'))
            ->redirect(route('tbtop.cms.page-edit-page', ['page' => $this->pageId()]));
    }

    private function viewOnSiteAction(S $s): ActionBuilder
    {
        $path = $this->page()->pathFor($this->defaultLocale());

        return $s->action('pageEditView')->label(__('tbtop-cms::pages.actions.view_on_site'))
            ->icon('external-link')->url('/' . ltrim((string) $path, '/'))->openUrlInNewTab();
    }

    /**
     * Not DeleteAction/RecordAction: those helpers are built for table row
     * actions (needs: ['row'], row-scoped model lookup via $ctx->row['id']).
     * This is a page-level footer action with no row payload — the target
     * record is the page resolved from the route param, same as save().
     */
    private function deleteAction(S $s): ActionBuilder
    {
        return $s->action('pageEditDelete')->label(__('tbtop-cms::pages.actions.delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.actions.delete_title'), __('tbtop-cms::pages.actions.delete_confirm'))
            ->handle(fn (ActionCtx $ctx): Effects => $this->delete());
    }

    /**
     * PageObserver::deleting() throws DomainException for a system page or
     * a page with children — the UI already hides the delete button for
     * both cases (see canDelete()), so this is defense-in-depth against any
     * caller that reaches the action without going through that guard (e.g.
     * a stale page in another open tab). Surfaced via notify(..., 'error')
     * rather than haltModal(): this is a plain footer action, not a modal.
     */
    private function delete(): Effects
    {
        $page = $this->page();

        try {
            $page->delete();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        event(new PageDeleted($page, forced: false));

        return Effects::make()->notify(__('tbtop-cms::pages.actions.deleted'))
            ->redirect(route('tbtop.cms.pages-page'));
    }

    /**
     * Mirrors PageObserver::deleting()'s invariants (system page, or a page
     * with children) so the delete button never appears for a page that
     * would fail to delete. children() is an existing hasMany — cheap
     * exists() check, no new query shape.
     */
    private function canDelete(Page $page): bool
    {
        return ! $page->is_system && ! $page->children()->exists();
    }

    private function page(): Page
    {
        return $this->pageCache ??= Page::query()->findOrFail($this->pageId());
    }

    private function pageId(): int
    {
        $id = request()->route('page');

        return is_string($id) ? (int) $id : 0;
    }

    private function defaultLocale(): string
    {
        return app(Languages::class)->defaultLocale();
    }
}
