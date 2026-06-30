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
use Tbtop\Cms\Admin\Pages\Support\TemplateForm;
use Tbtop\Cms\Page\Models\Page;
use Tbtop\Cms\Template\Models\Template;
use Tbtop\Cms\Template\TemplateApplier;

class TemplatesPage extends AdminPage
{
    public static function path(): string
    {
        return 'templates';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return ['group' => 'CMS', 'label' => 'Templates', 'order' => 40];
    }

    #[\Override]
    public function title(): string
    {
        return 'Templates';
    }

    public function view(S $s): Node
    {
        $table = $s->table('templates')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label('Name')->searchable(),
                Column::make('updated_at')->label('Updated'),
            ])
            ->searchable(['name'])
            ->defaultSort('name', 'asc')
            ->emptyState('No templates yet', 'Create your first template.', 'layout-template')
            ->headerActions([$this->createAction($s)])
            ->rowActions([$this->applyAction($s), $this->editAction($s), $this->deleteAction($s)]);

        return $s->stack([$table]);
    }

    /** @return Builder<Template> */
    private function query(): Builder
    {
        return Template::query();
    }

    private function createAction(S $s): ActionBuilder
    {
        $form = $s->form('templateCreate', [
            ...TemplateForm::fields($s),
            $s->actionsRow([
                $s->action('templateCreateSave')->label('Create')->color('primary')
                    ->handle($this->create(...), needs: ['form']),
                $s->action('templateCreateCancel')->label('Cancel')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('templateCreate')->label('New template')->modal('New template', $form);
    }

    private function editAction(S $s): ActionBuilder
    {
        return EditAction::make(
            $s,
            $s->form('templateEdit', TemplateForm::fields($s)),
            static fn (ActionCtx $ctx): array => TemplateForm::loadRow((int) ($ctx->row['id'] ?? 0)),
            static fn (ActionCtx $ctx): Template => TemplateForm::save($ctx->form, (int) ($ctx->row['id'] ?? 0)),
            name: 'templateEdit',
            title: 'Edit template',
        );
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static fn (ActionCtx $ctx) => Template::query()->find($ctx->row['id'] ?? null)?->delete(),
            'templateDelete',
        );
    }

    private function applyAction(S $s): ActionBuilder
    {
        $form = $s->form('templateApply', [
            $s->select('target_page_id')->label('Page')->required()->options(TemplateForm::pageOptions()),
            $s->actionsRow([
                $s->action('templateApplySave')->label('Apply')->color('primary')
                    ->handle($this->apply(...), needs: ['row', 'form']),
                $s->action('templateApplyCancel')->label('Cancel')
                    ->handle(static fn (): Effects => Effects::make()->closeModal()),
            ]),
        ]);

        return $s->action('templateApply')->label('Apply to page')->modal('Apply to page', $form);
    }

    private function create(ActionCtx $ctx): Effects
    {
        TemplateForm::save($ctx->form);

        return Effects::make()->notify('Template created')->closeModal()->refreshTable();
    }

    private function apply(ActionCtx $ctx): Effects
    {
        $template = Template::query()->findOrFail((int) ($ctx->row['id'] ?? 0));
        $page = Page::query()->findOrFail((int) ($ctx->form['target_page_id'] ?? 0));

        app(TemplateApplier::class)->applyTo($template, $page);

        return Effects::make()->notify('Template applied')->closeModal()->refreshTable();
    }
}
