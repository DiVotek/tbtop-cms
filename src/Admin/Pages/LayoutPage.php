<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page as AdminPage;
use Tbtop\Cms\Admin\Pages\Support\LayoutForm;
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
        return ['group' => 'CMS', 'label' => 'Layout', 'order' => 42];
    }

    #[\Override]
    public function title(): string
    {
        return 'Layout';
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
            $s->action('edit-' . $slot)->label('Edit')->visit(
                route('tbtop.cms.layout-edit-page', ['slot' => $slot]),
            ),
        ]);
    }
}
