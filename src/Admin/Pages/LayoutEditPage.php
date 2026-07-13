<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Admin\Support\EditFooter;
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
        return __('tbtop-cms::layout.edit_title', ['slot' => LayoutForm::label($this->slot())]);
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.layout'), 'url' => $this->listUrl()],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $slot = $this->slot();
        $footer = EditFooter::build($s, $this->listUrl(), $this->saveAction($s));

        $form = $s->form('layoutEditor', [
            ...LayoutForm::fields($s),
            $footer,
        ])
            ->record(LayoutForm::loadRow($slot))
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::layout.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit('layoutEditor');
    }

    private function save(ActionCtx $ctx): Effects
    {
        LayoutForm::save($ctx->form, $this->slot());

        return Effects::make()->notify(__('tbtop-cms::layout.actions.saved'))->redirect($this->listUrl());
    }

    private function slot(): string
    {
        $slot = request()->route('slot');
        $slot = is_string($slot) ? $slot : '';

        abort_unless(in_array($slot, Slots::all(), true), 404);

        return $slot;
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.layout-page');
    }
}
