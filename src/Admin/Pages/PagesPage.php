<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\EditAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\Tab;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\PageForm;
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
                    ->formatUsing(self::typeLabel(...))
                    ->muted()->uppercase(),
                Column::make('parent_name')->label(__('tbtop-cms::pages.columns.parent'))->translatable()->muted(),
                Column::make('status')->label(__('tbtop-cms::pages.columns.status'))
                    ->formatUsing(self::statusLabel(...))
                    ->badge([
                        __('tbtop-cms::pages.status.published') => 'success',
                        __('tbtop-cms::pages.status.draft') => 'warning',
                    ]),
                Column::make('updated_at')->label(__('tbtop-cms::pages.columns.updated'))
                    ->date(__('tbtop-cms::pages.date_format'))->muted(),
            ])
            ->tabs([
                self::tab('all'),
                self::tab('categories')
                    ->query(static fn (Builder $q): Builder => $q->where('type', 'category')),
                self::tab('pages')
                    ->query(static fn (Builder $q): Builder => $q->where('type', '!=', 'category')->whereNull('parent_id')),
                self::tab('articles')
                    ->query(static fn (Builder $q): Builder => $q->where('type', '!=', 'category')->whereNotNull('parent_id')),
            ])
            ->toolbar(false)
            ->defaultSort('sort_order', 'asc')
            ->emptyState(__('tbtop-cms::pages.empty'))
            ->rowClick('pageEdit')
            ->rowActions([
                // Hidden: exists only as the rowClick target (title/row opens edit).
                $this->editAction($s)->meta('hidden', true),
                $this->viewOnSiteAction($s),
            ]);

        return $s->stack([$table]);
    }

    /** @return Builder<Page> */
    private function query(): Builder
    {
        $default = app(Languages::class)->defaultLocale();

        return Page::query()->select('pages.*')
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
    }

    private function createAction(S $s): ActionBuilder
    {
        $form = $s->form('pageCreate', [
            ...PageForm::fields($s, isEdit: false),
            $s->actionsRow([
                $s->action('pageCreateSave')->label(__('tbtop-cms::pages.actions.create'))->color('primary')
                    ->handle($this->create(...), needs: ['form']),
                $s->action('pageCreateCancel')->label(__('tbtop-cms::pages.actions.cancel'))
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('pageCreate')->label(__('tbtop-cms::pages.actions.new'))->icon('plus')->color('primary')
            ->modal(__('tbtop-cms::pages.actions.create_title'), $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('pageEdit', PageForm::fields($s, isEdit: true)),
            static fn (ActionCtx $ctx): array => PageForm::loadRow((int) ($ctx->row['id'] ?? 0)),
            static fn (ActionCtx $ctx): Page => PageForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0)),
            name: 'pageEdit',
            title: __('tbtop-cms::pages.actions.edit_title'),
        );
    }

    private function viewOnSiteAction(S $s): ActionBuilder
    {
        // label('') → icon-only; the tooltip carries the accessible name.
        return $s->action('pageView')->label('')->link()->icon('external-link')
            ->tooltip(__('tbtop-cms::pages.actions.view_on_site'))
            ->visit('/{path}', newTab: true);
    }

    private static function tab(string $name): Tab
    {
        $label = __('tbtop-cms::pages.tabs.' . $name);

        return Tab::make($name)->label($label)
            ->description(__('tbtop-cms::pages.tab_description', ['tab' => $label]));
    }

    private static function typeLabel(mixed $value): string
    {
        $key = 'tbtop-cms::pages.types.' . (string) $value;
        $label = __($key);

        return $label === $key ? ucfirst((string) $value) : $label;
    }

    private static function statusLabel(mixed $value): string
    {
        $key = 'tbtop-cms::pages.status.' . (string) $value;
        $label = __($key);

        return $label === $key ? ucfirst((string) $value) : $label;
    }

    private function create(ActionCtx $ctx): Effects
    {
        PageForm::save($ctx->form);

        return Effects::make()->notify(__('tbtop-cms::pages.actions.created'))->closeModal()->refreshTable();
    }
}
