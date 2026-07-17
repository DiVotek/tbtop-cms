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
use Tbtop\Cms\Admin\Pages\Support\TemplateForm;
use Tbtop\Cms\Admin\Support\AuthorNames;
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
        return [
            'group' => 'design',
            'label' => __('tbtop-cms::nav.items.templates'),
            'order' => 10,
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return 'tbtop-cms.design';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::templates.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::templates.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.templates')],
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
            route('tbtop.cms.template-edit-page', ['template' => '__ROWID__']),
        );

        $table = $s->table('templates')
            ->query($this->query(...))
            ->columns([
                Column::make('name')->label(__('tbtop-cms::templates.columns.name'))->searchable(),
                Column::make('updated_at')->label(__('tbtop-cms::templates.columns.updated'))
                    ->date(__('tbtop-cms::templates.date_format'))->muted(),
                Column::make('updated_by_name')->label(__('tbtop-cms::templates.columns.updated_by'))
                    ->toggleable(true, hiddenByDefault: true)->muted(),
            ])
            ->searchable(['name'])
            ->defaultSort('name', 'asc')
            ->emptyState(
                __('tbtop-cms::templates.empty.title'),
                __('tbtop-cms::templates.empty.description'),
                'layout-template',
            )
            ->rowActions([
                $this->applyAction($s),
                $s->action('templateEdit')->label(__('tbtop-cms::templates.actions.edit'))
                    ->visit($editHref),
                $this->deleteAction($s),
            ]);

        return $s->stack([$table]);
    }

    private function createAction(S $s): ActionBuilder
    {
        return $s->action('templateCreate')->label(__('tbtop-cms::templates.actions.new'))
            ->icon('plus')->color('primary')
            ->visit(route('tbtop.cms.template-create-page'));
    }

    /** @return Builder<Template> */
    private function query(): Builder
    {
        return Template::query()->select('cms_templates.*')
            ->selectSub(AuthorNames::subselect('cms_templates', 'updated_by'), 'updated_by_name');
    }

    private function deleteAction(S $s): ActionBuilder
    {
        return DeleteAction::make(
            $s,
            static function (ActionCtx $ctx): Effects {
                Template::query()->find($ctx->row['id'] ?? null)?->delete();

                return Effects::make()->notify(__('tbtop-cms::templates.actions.deleted'))->refreshTable();
            },
            'templateDelete',
        )->label(__('tbtop-cms::templates.actions.delete'))
            ->confirm(__('tbtop-cms::templates.actions.delete_title'), __('tbtop-cms::templates.actions.delete_confirm'));
    }

    private function applyAction(S $s): ActionBuilder
    {
        $cancel = $s->action('templateApplyCancel')->label(__('tbtop-cms::templates.actions.cancel'))
            ->handle(static fn (): Effects => Effects::make()->closeModal());
        $apply = $s->action('templateApplySave')->label(__('tbtop-cms::templates.actions.apply'))->color('primary')
            ->handle($this->apply(...), needs: ['row', 'form']);

        $form = $s->form('templateApply', [
            $s->select('target_page_id')->label(__('tbtop-cms::templates.fields.target_page'))
                ->required()->options(TemplateForm::pageOptions()),
            $s->flex([$s->spacer(), $cancel, $apply], justify: 'end'),
        ]);

        return $s->action('templateApply')->label(__('tbtop-cms::templates.actions.apply_title'))
            ->modal(__('tbtop-cms::templates.actions.apply_title'), $form);
    }

    private function apply(ActionCtx $ctx): Effects
    {
        $template = Template::query()->findOrFail((int) ($ctx->row['id'] ?? 0));
        $page = Page::query()->findOrFail((int) ($ctx->form['target_page_id'] ?? 0));

        app(TemplateApplier::class)->applyTo($template, $page);

        return Effects::make()->notify(__('tbtop-cms::templates.actions.applied'))->closeModal()->refreshTable();
    }
}
