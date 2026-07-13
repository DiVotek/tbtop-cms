<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

class TemplateEditPage extends TemplateFormPage
{
    public static function path(): string
    {
        return 'templates/{template}/edit';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::templates.edit_title');
    }

    protected function templateId(): ?int
    {
        $id = request()->route('template');

        return is_string($id) ? (int) $id : null;
    }
}
