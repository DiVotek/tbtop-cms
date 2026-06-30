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
use Tbtop\Cms\Admin\Pages\Support\RedirectForm;
use Tbtop\Cms\Redirect\Models\Redirect;

class RedirectsPage extends AdminPage
{
    public static function path(): string
    {
        return 'redirects';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return ['group' => 'CMS', 'label' => 'Redirects', 'order' => 30];
    }

    #[\Override]
    public function title(): string
    {
        return 'Redirects';
    }

    public function view(S $s): Node
    {
        $table = $s->table('redirects')
            ->query($this->query(...))
            ->columns([
                Column::make('from_path')->label('From')->searchable(),
                Column::make('to_path')->label('To')->searchable(),
                Column::make('locale')->label('Locale'),
                Column::make('status_code')->label('Status'),
                Column::make('is_auto')->label('Auto')->boolean(),
            ])
            ->searchable(['from_path', 'to_path'])
            ->defaultSort('from_path', 'asc')
            ->emptyState('No redirects yet', 'Create your first redirect.', 'arrow-right')
            ->headerActions([$this->createAction($s)])
            ->rowActions([$this->editAction($s), $this->deleteAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<Redirect> */
    private function query(): Builder
    {
        return Redirect::query();
    }

    private function createAction(S $s): ActionBuilder
    {
        $form = $s->form('redirectCreate', [
            ...RedirectForm::fields($s),
            $s->actionsRow([
                $s->action('redirectCreateSave')->label('Create')->color('primary')
                    ->handle($this->create(...), needs: ['form']),
                $s->action('redirectCreateCancel')->label('Cancel')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('redirectCreate')->label('New redirect')->modal('New redirect', $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('redirectEdit', RedirectForm::fields($s)),
            static fn (ActionCtx $ctx): array => RedirectForm::loadRow((int) ($ctx->row['id'] ?? 0)),
            static fn (ActionCtx $ctx): Redirect => RedirectForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0)),
            name: 'redirectEdit',
            title: 'Edit redirect',
        );
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static fn (ActionCtx $ctx) => Redirect::query()->find($ctx->row['id'] ?? null)?->delete(),
            'redirectDelete',
        );
    }

    private function create(ActionCtx $ctx): Effects
    {
        RedirectForm::save($ctx->form);

        return Effects::make()->notify('Redirect created')->closeModal()->refreshTable();
    }
}
