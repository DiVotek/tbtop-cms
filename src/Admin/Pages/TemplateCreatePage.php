<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

class TemplateCreatePage extends TemplateFormPage
{
    public static function path(): string
    {
        return 'templates/create';
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::templates.create_title');
    }

    protected function templateId(): ?int
    {
        return null;
    }
}
