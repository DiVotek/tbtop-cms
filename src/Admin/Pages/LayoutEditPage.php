<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Layout\Slots;

class LayoutEditPage extends Page
{
    public static function path(): string
    {
        return 'layout/{slot}/edit';
    }

    #[\Override]
    public function title(): string
    {
        return 'Edit ' . LayoutForm::label($this->slot()) . ' layout';
    }

    public function view(S $s): Node
    {
        $slot = $this->slot();

        $form = $s->form('layoutEditor', [
            ...LayoutForm::fields($s),
            $s->actionsRow([
                $s->action('save')->label('Save')->color('primary')->keybinding('mod+s')->submit(),
                $s->action('cancel')->label('Cancel')->visit($this->listUrl()),
            ]),
        ])
            ->record(LayoutForm::loadRow($slot))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function save(ActionCtx $ctx): Effects
    {
        LayoutForm::save($ctx->form, $this->slot());

        return Effects::make()->notify('Layout saved')->redirect($this->listUrl());
    }

    private function slot(): string
    {
        $slot = request()->route('slot');
        $slot = is_string($slot) ? $slot : '';

        return in_array($slot, Slots::all(), true) ? $slot : Slots::HEADER;
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.layout-page');
    }
}
