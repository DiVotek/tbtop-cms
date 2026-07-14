<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

class MenuEditPage extends MenuFormPage
{
    public static function path(): string
    {
        return 'menus/{menu}/edit';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::menus.edit_title');
    }

    protected function menuId(): ?int
    {
        $id = request()->route('menu');

        return is_string($id) ? (int) $id : null;
    }
}
