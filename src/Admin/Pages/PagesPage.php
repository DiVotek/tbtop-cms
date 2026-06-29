<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Database\Eloquent\Builder;
use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Actions\DeleteAction;
use Tbtop\Admin\Dsl\Actions\EditAction;
use Tbtop\Admin\Dsl\Column;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
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
        return ['group' => 'CMS', 'label' => 'Pages', 'order' => 10];
    }

    #[\Override]
    public function title(): string
    {
        return 'Pages';
    }

    public function view(S $s): Node
    {
        $table = $s->table('pages')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label('Name')->translatable()->searchable(),
                Column::make('type')->label('Type'),
                Column::make('status')->label('Status'),
                Column::make('path')->label('Path'),
                Column::make('is_system')->label('System')->boolean(),
            ])
            ->searchable(['name'])
            ->defaultSort('sort_order', 'asc')
            ->emptyState('No pages yet', 'Create your first page.', 'file-text')
            ->headerActions([$this->createAction($s)])
            ->rowActions([$this->editAction($s), $this->deleteAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<Page> */
    private function query(): Builder
    {
        $default = app(Languages::class)->defaultLocale();

        return Page::query()->select('pages.*')->selectSub(
            PageSlug::query()->select('path')
                ->whereColumn('page_id', 'pages.id')
                ->where('locale', $default)
                ->limit(1),
            'path',
        );
    }

    private function createAction(S $s): ActionBuilder
    {
        $form = $s->form('pageCreate', [
            ...PageForm::fields($s, isEdit: false),
            $s->actionsRow([
                $s->action('pageCreateSave')->label('Create')->color('primary')
                    ->handle($this->create(...), needs: ['form']),
                $s->action('pageCreateCancel')->label('Cancel')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('pageCreate')->label('New page')->modal('New page', $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('pageEdit', PageForm::fields($s, isEdit: true)),
            static fn (ActionCtx $ctx): array => PageForm::loadRow((int) ($ctx->row['id'] ?? 0)),
            static fn (ActionCtx $ctx): Page => PageForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0)),
            name: 'pageEdit',
            title: 'Edit page',
        );
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static fn (ActionCtx $ctx) => Page::query()->find($ctx->row['id'] ?? null)?->delete(),
            'pageDelete',
        )->hiddenIf('is_system', '=', true);
    }

    private function create(ActionCtx $ctx): Effects
    {
        PageForm::save($ctx->form);

        return Effects::make()->notify('Page created')->closeModal()->refreshTable();
    }
}
