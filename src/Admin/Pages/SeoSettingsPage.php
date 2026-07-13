<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\S;

class SeoSettingsPage extends SettingsPageBase
{
    public static function path(): string
    {
        return 'seo-settings';
    }

    /** @return array<string, mixed>|null */
    public static function nav(): ?array
    {
        return [
            'group' => 'settings',
            'label' => __('tbtop-cms::nav.items.seo_settings'),
            'order' => 30,
        ];
    }

    #[\Override]
    public function title(): string
    {
        return __('tbtop-cms::settings.seo.title');
    }

    #[\Override]
    public function subtitle(): ?string
    {
        return __('tbtop-cms::settings.seo.subtitle');
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
            'seo_title_suffix' => 'seo.title_suffix',
            'seo_default_description' => 'seo.default_description',
            'seo_default_og_image' => 'seo.default_og_image',
            'seo_indexing_enabled' => 'seo.indexing_enabled',
        ];
    }

    /** @return list<Field> */
    protected function fields(S $s): array
    {
        return [
            $s->text('seo_title_suffix')->label(__('tbtop-cms::settings.seo.fields.title_suffix'))
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.title_suffix'))->rules('nullable|max:255'),
            $s->textarea('seo_default_description')->label(__('tbtop-cms::settings.seo.fields.default_description'))
                ->rules('nullable|max:500'),
            $s->media('seo_default_og_image')->label(__('tbtop-cms::settings.seo.fields.default_og_image'))->variant('preview'),
            $s->boolean('seo_indexing_enabled')->label(__('tbtop-cms::settings.seo.fields.indexing_enabled')),
        ];
    }
}
