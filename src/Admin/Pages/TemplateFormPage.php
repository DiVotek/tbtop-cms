<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\TemplateForm;

/**
 * Shared block-repeater editor for templates. Create and edit are dedicated
 * routes (not modals) so the visual block repeater has room to work; the
 * subclass only decides which template id, if any, backs the form.
 */
abstract class TemplateFormPage extends Page
{
    abstract protected function templateId(): ?int;

    public function view(S $s): Node
    {
        $id = $this->templateId();

        $form = $s->form('templateEditor', [
            ...TemplateForm::fields($s),
            $s->actionsRow([
                $s->action('save')->label('Save')->color('primary')->keybinding('mod+s')->submit(),
                $s->action('cancel')->label('Cancel')->visit($this->listUrl()),
            ]),
        ])
            ->record($id !== null ? TemplateForm::loadRow($id) : [])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function save(ActionCtx $ctx): Effects
    {
        TemplateForm::save($ctx->form, $this->templateId());

        return Effects::make()->notify('Template saved')->redirect($this->listUrl());
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.templates-page');
    }
}
