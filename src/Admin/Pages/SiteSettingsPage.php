<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

class SiteSettingsPage extends SettingsPageBase
{
    public static function path(): string
    {
        return 'site-settings';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.site_settings'),
            'order' => 20,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return 'Site settings';
    }

    /** @return array<string, string> */
    protected function map(): array
    {
        return [
            'site_name' => 'site.name',
            'site_logo' => 'site.logo',
            'site_favicon' => 'site.favicon',
        ];
    }

    /** @return list<Field> */
    protected function fields(S $s): array
    {
        return [
            $s->text('site_name')->label('Site name')->required()->rules('max:255'),
            $s->media('site_logo')->label('Logo'),
            $s->media('site_favicon')->label('Favicon'),
        ];
    }
}
