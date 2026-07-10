<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;

/**
 * Dedicated full-page editor for a single page record (name/content/blocks +
 * SEO). Not in the nav — reached via the pages list's row click / quick-edit
 * actions.
 *
 * The "Основне"/"SEO" groups are rendered as two stacked sections rather than
 * S::tabs(): tabs() stores its content under options['tabs'][*]['body'],
 * which neither RuleWalker::collect() nor TranslatableRecord::normalize()
 * walk (both only look at options['children']). Inside a real S::tabs() form,
 * required()/translatable() fields would silently drop out of $ctx->form on
 * submit (FormSubmitController passes $request->validate($form->collectRules())
 * straight into the handler — an empty rule set means the key never reaches
 * $validated). That's a framework gap, not a style choice; sections are the
 * only grouping primitive that currently participates in both walkers.
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
     * Only ->visit() header actions would work without this wiring: the
     * ActionController's per-request S is rebuilt from view() alone
     * (ResolvedPage::fromRequest never calls headerActions()), so a
     * ->handle() action built only inside headerActions() registers on the
     * GET render but 404s on its own POST .../actions/{name} dispatch. Delete
     * is therefore also (re-)built as a byproduct of view() — see
     * registerDeleteAction() — so both paths see the same action name.
     *
     * @return list<ActionBuilder|Node>
     */
    #[\Override]
    public function headerActions(S $s): array
    {
        $actions = [$this->viewOnSiteAction($s)];

        if (! $this->page()->is_system) {
            $actions[] = $this->deleteAction($s);
        }

        return $actions;
    }

    public function view(S $s): Node
    {
        $form = $s->form('pageEditor', [
            $s->section(['title' => __('tbtop-cms::pages.tabs_edit.general')], PageForm::generalFields($s)),
            $s->section(['title' => __('tbtop-cms::pages.tabs_edit.seo')], PageForm::seoFields($s)),
            $s->actionsRow([
                $s->action('save')->label(__('tbtop-cms::pages.actions.save'))->color('primary')
                    ->keybinding('mod+s')->submit(),
            ]),
        ])
            ->record(PageForm::loadRow($this->pageId()))
            ->onSubmit($this->save(...));

        $this->registerDeleteAction($s);

        return $s->stack([$form]);
    }

    /** Registers (without rendering) the delete action so it exists in $s->collectedActions() on the action-dispatch path. */
    private function registerDeleteAction(S $s): void
    {
        if (! $this->page()->is_system) {
            $this->deleteAction($s);
        }
    }

    private function save(ActionCtx $ctx): Effects
    {
        PageForm::save($ctx->form, $this->pageId());

        return Effects::make()->notify(__('tbtop-cms::pages.actions.saved'))->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $this->pageId()]),
        );
    }

    private function viewOnSiteAction(S $s): ActionBuilder
    {
        $path = $this->page()->pathFor($this->defaultLocale());

        return $s->action('pageEditView')->label(__('tbtop-cms::pages.actions.view_on_site'))
            ->icon('external-link')->visit('/' . ltrim((string) $path, '/'), newTab: true);
    }

    /**
     * Not DeleteAction/RecordAction: those helpers are built for table row
     * actions (needs: ['row'], row-scoped model lookup via $ctx->row['id']).
     * This is a page-level header action with no row payload — the target
     * record is the page resolved from the route param, same as save().
     */
    private function deleteAction(S $s): ActionBuilder
    {
        return $s->action('pageEditDelete')->label(__('tbtop-cms::pages.actions.delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.actions.delete_title'), __('tbtop-cms::pages.actions.delete_confirm'))
            ->handle(fn (ActionCtx $ctx): Effects => $this->delete());
    }

    private function delete(): Effects
    {
        $this->page()->delete();

        return Effects::make()->notify(__('tbtop-cms::pages.actions.deleted'))
            ->redirect(route('tbtop.cms.pages-page'));
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
