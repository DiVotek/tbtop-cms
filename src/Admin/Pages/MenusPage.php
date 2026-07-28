<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\DeleteAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Menu\Models\Menu;

class MenusPage extends AdminPage
{
    public static function path(): string
    {
        return 'menus';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'design',
            'label' => __('tbtop-cms::nav.items.menus'),
            'order' => 30,
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.menus');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::menus.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::menus.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.menus')],
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
        $editHref = str_replace(
            '__ROWID__',
            '{row.id}',
            route('tbtop.cms.menu-edit-page', ['menu' => '__ROWID__']),
        );

        $table = $s->table('menus')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::menus.columns.name'))->searchable(),
                Column::make('key')->label(__('tbtop-cms::menus.columns.key'))->searchable()->muted(),
                Column::make('updated_at')->label(__('tbtop-cms::menus.columns.updated'))
                    ->date(__('tbtop-cms::menus.date_format'))->muted(),
            ])
            ->searchable(['name', 'key'])
            ->defaultSort('name', 'asc')
            ->emptyState(
                __('tbtop-cms::menus.empty.title'),
                __('tbtop-cms::menus.empty.description'),
                'menu',
            )
            ->rowActions([
                $s->action('menuEdit')->label(__('tbtop-cms::menus.actions.edit'))
                    ->visit($editHref),
                $this->deleteAction($s),
            ]);

        return $s->stack([$table]);
    }

    private function createAction(S $s): ActionBuilder
    {
        return $s->action('menuCreate')->label(__('tbtop-cms::menus.actions.new'))
            ->icon('plus')->color('primary')
            ->visit(route('tbtop.cms.menu-create-page'));
    }

    /** @return Builder<Menu> */
    private function query(): Builder
    {
        return Menu::query();
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                Menu::query()->find($ctx->row['id'] ?? null)?->delete();

                return Effects::make()->notify(__('tbtop-cms::menus.actions.deleted'))->refreshTable();
            },
            'menuDelete',
        )->label(__('tbtop-cms::menus.actions.delete'))
            ->confirm(__('tbtop-cms::menus.actions.delete_title'), __('tbtop-cms::menus.actions.delete_confirm'));
    }
}
