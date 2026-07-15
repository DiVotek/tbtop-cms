<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Actions\ActionCtx;
use Tbtop\Admin\Actions\Effects;
use Tbtop\Admin\Dsl\ActionBuilder;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;
use Tbtop\Cms\Admin\Pages\Support\MenuForm;
use Tbtop\Cms\Admin\Support\EditFooter;

/**
 * Shared editor for site menus. Create and edit are dedicated routes (not
 * modals) so the nested collapsible items repeater has room to work; the
 * subclass only decides which menu id, if any, backs the form.
 */
abstract class MenuFormPage extends Page
{
    abstract protected function menuId(): ?int;

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.menus'), 'url' => route('tbtop.cms.menus-page')],
            ['label' => $this->title()],
        ];
    }

    public function view(S $s): Node
    {
        $id = $this->menuId();
        $footer = EditFooter::build($s, $this->listUrl(), $this->saveAction($s));

        $form = $s->form('menuEditor', [
            ...MenuForm::fields($s, isEdit: $id !== null),
            $footer,
        ])
            ->record($id !== null ? MenuForm::loadRow($id) : [])
            ->onSubmit($this->save(...));

        return $s->stack([$form]);
    }

    private function saveAction(S $s): ActionBuilder
    {
        return $s->action('save')->label(__('tbtop-cms::menus.actions.save'))
            ->color('primary')->keybinding('mod+s')->submit('menuEditor');
    }

    private function save(ActionCtx $ctx): Effects
    {
        MenuForm::save($ctx->form, $this->menuId());

        return Effects::make()->notify(__('tbtop-cms::menus.actions.saved'))->redirect($this->listUrl());
    }

    private function listUrl(): string
    {
        return route('tbtop.cms.menus-page');
    }
}
