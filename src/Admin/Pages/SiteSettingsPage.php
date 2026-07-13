<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
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
        return __('tbtop-cms::settings.site.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::settings.site.subtitle');
    }

    /** @return list<array{label: string, url?: string}> */
    #[\Override]
    public function breadcrumbs(): array
    {
        return [
            ['label' => __('tbtop-cms::nav.groups.settings')],
            ['label' => $this->title()],
        ];
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

    /** @return list<Field|Node> */
    protected function fields(S $s): array
    {
        return [
            $s->text('site_name')->label(__('tbtop-cms::settings.site.fields.site_name'))->required()->rules('max:255'),
            $s->grid(['cols' => 2], [
                $s->media('site_logo')->label(__('tbtop-cms::settings.site.fields.logo'))->variant('preview'),
                $s->media('site_favicon')->label(__('tbtop-cms::settings.site.fields.favicon'))->variant('preview'),
            ]),
        ];
    }
}
