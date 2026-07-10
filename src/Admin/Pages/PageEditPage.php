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
 * The General/SEO groups are real S::tabs(): since tbtop/admin 0.2.19 the
 * form walkers (RuleWalker, TranslatableRecord) recurse into tab bodies, so
 * fields inside tabs validate and reach $ctx->form like any other field.
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

    /** @return list<ActionBuilder|Node> */
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
            $s->tabs([
                ['label' => __('tbtop-cms::pages.tabs_edit.general'), 'body' => $s->stack(PageForm::generalFields($s))],
                ['label' => __('tbtop-cms::pages.tabs_edit.seo'), 'body' => $s->stack(PageForm::seoFields($s))],
            ]),
            $s->actionsRow([
                $s->action('save')->label(__('tbtop-cms::pages.actions.save'))->color('primary')
                    ->keybinding('mod+s')->submit(),
            ]),
        ])
            ->record(PageForm::loadRow($this->pageId()))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
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
