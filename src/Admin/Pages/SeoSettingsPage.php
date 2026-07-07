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
        return ['group' => 'CMS', 'label' => 'SEO settings', 'order' => 50];
    }

    #[\Override]
    public function title(): string
    {
        return 'SEO settings';
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
            $s->text('seo_title_suffix')->label('Title suffix')->rules('nullable|max:255'),
            $s->textarea('seo_default_description')->label('Default description')->rules('nullable|max:500'),
            $s->media('seo_default_og_image')->label('Default OG image'),
            $s->boolean('seo_indexing_enabled')->label('Allow search engines to index this site'),
        ];
    }
}
