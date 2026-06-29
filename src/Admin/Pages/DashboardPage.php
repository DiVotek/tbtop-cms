<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Pages\Page;

class DashboardPage extends Page
{
    public static function path(): string
    {
        return 'dashboard';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return ['group' => 'CMS', 'label' => 'Dashboard', 'order' => 0];
    }

    #[\Override]
    public function title(): string
    {
        return 'Dashboard';
    }

    public function view(S $s): Node
    {
        return $s->stack([
            $s->displayText('CMS admin')->variant('heading'),
            $s->displayText('Welcome to the tbtop CMS.')->variant('muted'),
        ]);
    }
}
