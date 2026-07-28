<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
use Tbtop\Cms\Admin\Support\CmsAbilities;
use Tbtop\Cms\Layout\Slots;

class LayoutPage extends AdminPage
{
    public static function path(): string
    {
        return 'layout';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'design',
            'label' => __('tbtop-cms::nav.items.layout'),
            'order' => 20,
        ];
    }

    #[\Override]
    public static function can(): ?string
    {
        return CmsAbilities::for('tbtop-cms.design');
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::layout.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::layout.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.design')],
            ['label' => __('tbtop-cms::nav.items.layout')],
        ];
    }

    public function view(S $s): Node
    {
        $rows = array_map(fn (string $slot): Node => $this->slotRow($s, $slot), Slots::all());

        return $s->stack($rows);
    }

    private function slotRow(S $s, string $slot): Node
    {
        return $s->row([
            $s->displayText(LayoutForm::label($slot)),
            $s->action('edit-' . $slot)->label(__('tbtop-cms::layout.actions.edit'))->visit(
                route('tbtop.cms.layout-edit-page', ['slot' => $slot]),
            ),
        ]);
    }
}
