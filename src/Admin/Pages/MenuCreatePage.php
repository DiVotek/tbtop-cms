<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

class MenuCreatePage extends MenuFormPage
{
    public static function path(): string
    {
        return 'menus/create';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::menus.create_title');
    }

    protected function menuId(): ?int
    {
        return null;
    }
}
