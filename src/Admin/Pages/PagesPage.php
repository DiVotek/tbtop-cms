<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
use Tbtop\Cms\Admin\Pages\Support\PageSortKey;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Page\Models\PageSlug;

class PagesPage extends AdminPage
{
    public static function path(): string
    {
        return 'pages';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'content',
            'label' => __('tbtop-cms::nav.items.pages'),
            'order' => 10,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::pages.title');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.content')],
            ['label' => __('tbtop-cms::nav.items.pages')],
        ];
    }

    /** @return list<ActionBuilder|Node> */
    #[\Override]
    public function headerActions(S $s): array
    {
        return [$this->createAction($s)];
    }

    public function view(S $s): Node
    {
        $table = $s->table('pages')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::pages.columns.title'))->translatable()->emphasized(),
                Column::make('type')->label(__('tbtop-cms::pages.columns.type'))
                    ->formatUsing($this->typeLabel(...))
                    ->muted()->uppercase(),
                Column::make('parent_name')->label(__('tbtop-cms::pages.columns.parent'))->translatable()->muted(),
                Column::make('status')->label(__('tbtop-cms::pages.columns.status'))
                    ->formatUsing($this->statusLabel(...))
                    ->badge([
                        __('tbtop-cms::pages.status.published') => 'success',
                        __('tbtop-cms::pages.status.draft') => 'warning',
                    ]),
                Column::make('updated_at')->label(__('tbtop-cms::pages.columns.updated'))
                    ->date(__('tbtop-cms::pages.date_format'))->muted(),
                // Not shown by default — projected purely so hiddenIf() can
                // distinguish trashed rows client-side (empty vs non-empty).
                Column::make('deleted_at')->label(__('tbtop-cms::pages.columns.deleted_at'))
                    ->date(__('tbtop-cms::pages.date_format'))->toggleable(true, hiddenByDefault: true),
            ])
            ->tabs([
                $this->tab('all'),
                $this->tab('categories')
                    ->query(static fn (Builder $q): Builder => $q->where('pages.type', 'category')),
                $this->tab('pages')
                    ->query(static fn (Builder $q): Builder => $q->where('pages.type', '!=', 'category')->whereNull('pages.parent_id')),
                $this->tab('articles')
                    ->query(static fn (Builder $q): Builder => $q->where('pages.type', '!=', 'category')->whereNotNull('pages.parent_id')),
                Tab::make('deleted')->label(__('tbtop-cms::pages.tabs.deleted'))
                    ->description(__('tbtop-cms::pages.tabs_deleted_description'))
                    ->query($this->onlyTrashedScope(...)),
            ])
            ->searchable(['pages.name'])
            ->defaultSort('sort_key', 'asc')
            ->emptyState(__('tbtop-cms::pages.empty'))
            // null for a trashed row: no edit page for a soft-deleted record,
            // so the row falls back to non-clickable (see tableRow.tsx —
            // recordUrl is only honored when _recordUrl is a string).
            ->recordUrl(static fn (Page $row): ?string => $row->trashed()
                ? null
                : route('tbtop.cms.page-edit-page', ['page' => $row->id]))
            ->rowActions([
                $this->viewOnSiteAction($s),
                $this->restoreAction($s),
                $this->forceDeleteAction($s),
            ]);

        return $s->stack([$table]);
    }

    /** @return Builder<Page> */
    private function query(): Builder
    {
        $default = app(Languages::class)->defaultLocale();

        $query = Page::query()->select('pages.*')
            ->selectSub(
                PageSlug::query()->select('path')
                    ->whereColumn('page_id', 'pages.id')
                    ->where('locale', $default)
                    ->limit(1),
                'path',
            )
            ->selectSub(
                DB::table('pages', 'parents')->select('parents.name')
                    ->whereColumn('parents.id', 'pages.parent_id')
                    ->whereNull('parents.deleted_at')
                    ->limit(1),
                'parent_name',
            );

        return PageSortKey::apply($query);
    }

    /**
     * @param  Builder<Page>  $q
     * @return Builder<Page>
     */
    private function onlyTrashedScope(Builder $q): Builder
    {
        return $q->onlyTrashed();
    }

    private function createAction(S $s): ActionBuilder
    {
        $cancel = $s->action('pageCreateCancel')->label(__('tbtop-cms::pages.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $create = $s->action('pageCreateSave')->label(__('tbtop-cms::pages.actions.create'))->color('primary')
            ->handle($this->create(...), needs: ['form']);

        $form = $s->form('pageCreate', [
            ...PageForm::fields($s),
            $s->flex([$s->spacer(), $cancel, $create], justify: 'end'),
        ]);

        return $s->action('pageCreate')->label(__('tbtop-cms::pages.actions.new'))->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::pages.actions.create_title'), $form);
    }

    private function viewOnSiteAction(S $s): ActionBuilder
    {
        // label('') → icon-only; the tooltip carries the accessible name.
        return $s->action('pageView')->label('')->link()->icon('external-link')
            ->tooltip(__('tbtop-cms::pages.actions.view_on_site'))
            ->visit('/{path}', newTab: true)
            // A trashed page has no live path (its slugs were removed by
            // PageObserver::deleting()) — the link would 404.
            ->hiddenIf('deleted_at', 'not empty');
    }

    private function restoreAction(S $s): ActionBuilder
    {
        return $s->action('pageRestore')->label(__('tbtop-cms::pages.actions.restore'))->color('gray')
            ->handle($this->restore(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function restore(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) ($ctx->row['id'] ?? 0));

        try {
            $page->restore();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.actions.restored'))->refreshTable();
    }

    private function forceDeleteAction(S $s): ActionBuilder
    {
        return $s->action('pageForceDelete')->label(__('tbtop-cms::pages.actions.force_delete'))->color('danger')
            ->confirm(__('tbtop-cms::pages.actions.force_delete_title'), __('tbtop-cms::pages.actions.force_delete_confirm'))
            ->handle($this->forceDelete(...), needs: ['row'])
            ->hiddenIf('deleted_at', 'empty');
    }

    private function forceDelete(ActionCtx $ctx): Effects
    {
        $page = Page::withTrashed()->findOrFail((int) ($ctx->row['id'] ?? 0));

        try {
            $page->forceDelete();
        } catch (DomainException $e) {
            return Effects::make()->notify($e->getMessage(), 'error');
        }

        return Effects::make()->notify(__('tbtop-cms::pages.actions.force_deleted'))->refreshTable();
    }

    private function tab(string $name): Tab
    {
        $label = __('tbtop-cms::pages.tabs.' . $name);

        return Tab::make($name)->label($label)
            ->description(__('tbtop-cms::pages.tab_description', ['tab' => $label]));
    }

    private function typeLabel(mixed $value): string
    {
        $key = 'tbtop-cms::pages.types.' . $value;
        $label = __($key);

        return $label === $key ? ucfirst((string) $value) : $label;
    }

    private function statusLabel(mixed $value): string
    {
        $key = 'tbtop-cms::pages.status.' . $value;
        $label = __($key);

        return $label === $key ? ucfirst((string) $value) : $label;
    }

    private function create(ActionCtx $ctx): Effects
    {
        $page = PageForm::save($ctx->form);

        return Effects::make()->notify(__('tbtop-cms::pages.actions.created'))->closeModal()->redirect(
            route('tbtop.cms.page-edit-page', ['page' => $page->id]),
        );
    }
}
