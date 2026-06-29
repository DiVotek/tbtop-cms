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
use Tbtop\Cms\Admin\Pages\Support\LanguageForm;
use Tbtop\Cms\Language\Models\Language;

class LanguagesPage extends AdminPage
{
    public static function path(): string
    {
        return 'languages';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return ['group' => 'CMS', 'label' => 'Languages', 'order' => 20];
    }

    #[\Override]
    public function title(): string
    {
        return 'Languages';
    }

    public function view(S $s): Node
    {
        $table = $s->table('languages')
            ->query(static fn (): Builder => Language::query())
            ->columns([
                Column::make('name')->label('Name')->searchable(),
                Column::make('locale')->label('Locale')->searchable(),
                Column::make('is_default')->label('Default')->boolean(),
                Column::make('is_admin_active')->label('Admin')->boolean(),
                Column::make('is_frontend_active')->label('Frontend')->boolean(),
                Column::make('sort_order')->label('Order'),
            ])
            ->searchable(['name', 'locale'])
            ->defaultSort('sort_order', 'asc')
            ->emptyState('No languages yet', 'Add your first language.', 'languages')
            ->headerActions([$this->createAction($s)])
            ->rowActions([$this->editAction($s), $this->deleteAction($s)]);

        return $s->stack([$table]);
    }

    private function createAction(S $s): ActionBuilder
    {
        $form = $s->form('languageCreate', [
            ...LanguageForm::fields($s),
            $s->actionsRow([
                $s->action('languageCreateSave')->label('Create')->color('primary')
                    ->handle($this->create(...), needs: ['form']),
                $s->action('languageCreateCancel')->label('Cancel')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('languageCreate')->label('New language')->modal('New language', $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('languageEdit', LanguageForm::fields($s, isEdit: true)),
            static fn (ActionCtx $ctx): array => LanguageForm::load((int) ($ctx->row['id'] ?? 0)),
            static fn (ActionCtx $ctx): Language => LanguageForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0)),
            name: 'languageEdit',
            title: 'Edit language',
        );
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static fn (ActionCtx $ctx) => Language::query()->find($ctx->row['id'] ?? null)?->delete(),
            'languageDelete',
        )->hiddenIf('is_default', '=', true);
    }

    private function create(ActionCtx $ctx): Effects
    {
        LanguageForm::save($ctx->form);

        return Effects::make()->notify('Language created')->closeModal()->refreshTable();
    }
}
