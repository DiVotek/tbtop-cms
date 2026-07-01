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
        return 'New template';
    }

    protected function templateId(): ?int
    {
        return null;
    }
}
