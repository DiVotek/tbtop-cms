<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\TemplateForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Admin\Support\EditFooter;

/**
 * Shared block-repeater editor for templates. Create and edit are dedicated
 * routes (not modals) so the visual block repeater has room to work; the
 * subclass only decides which template id, if any, backs the form.
 */
abstract class TemplateFormPage extends Page
{
    abstract protected function templateId(): ?int;

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.design');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.templates'), 'url' => route('tbtop.cms.templates-page')],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $id = $this->templateId();
        $footer = EditFooter::build($s, $this->listUrl(), $this->saveAction($s));

        $form = $s->form('templateEditor', [
            ...TemplateForm::fields($s),
            $footer,
        ])
            ->record($id !== null ? TemplateForm::loadRow($id) : [])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::templates.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit('templateEditor');
    }

    private function save(ActionCtx $ctx): Effects
    {
        $template = TemplateForm::save($ctx->form, $this->templateId());
        $effects = Effects::make()->notify(__('tbtop-cms::templates.actions.saved'));

        if ($this->templateId() === null) {
            return $effects->redirect(route('tbtop.cms.template-edit-page', ['template' => $template->id]));
        }

        return $effects;
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.templates-page');
    }
}
