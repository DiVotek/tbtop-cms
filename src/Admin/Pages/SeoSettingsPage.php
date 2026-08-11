<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;

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
            'icon' => 'search',
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

    /**
     * seo_title_suffix stays mapped (and functional under the hood in
     * SeoResolver — see its title()/titleWithSuffix()) but no longer has a
     * field below: seo_title_template replaces it in the UI, and both a
     * legacy-suffix site and one that never opens this page again must keep
     * rendering titles exactly as before. Never migrated/deleted.
     *
     * @return array<string, string>
     */
    protected function map(): array
    {
        return [
            'seo_title_suffix' => 'seo.title_suffix',
            'seo_title_template' => 'seo.title_template',
            'seo_title_separator' => 'seo.title_separator',
            'seo_description_template' => 'seo.description_template',
            'seo_default_description' => 'seo.default_description',
            'seo_default_og_image' => 'seo.default_og_image',
            'seo_indexing_enabled' => 'seo.indexing_enabled',
            'seo_gtm_container_id' => 'seo.gtm_container_id',
            'seo_identity_type' => 'seo.identity.type',
            'seo_identity_name' => 'seo.identity.name',
            'seo_identity_logo' => 'seo.identity.logo',
            'seo_identity_same_as' => 'seo.identity.same_as',
            'seo_twitter_card' => 'seo.twitter_card',
        ];
    }

    /** @return list<Field|Node|TextBlock> */
    protected function fields(S $s): array
    {
        return [
            $s->text('seo_title_template')->label(__('tbtop-cms::settings.seo.fields.title_template'))
                ->translatable()
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.title_template'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.title_template'))
                ->rules('nullable|max:255'),
            $s->text('seo_title_separator')->label(__('tbtop-cms::settings.seo.fields.title_separator'))
                ->placeholder('—')
                ->helperText(__('tbtop-cms::settings.seo.helpers.title_separator'))
                ->rules('nullable|max:10'),
            $s->text('seo_description_template')->label(__('tbtop-cms::settings.seo.fields.description_template'))
                ->translatable()
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.description_template'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.description_template'))
                ->rules('nullable|max:500'),
            $s->textarea('seo_default_description')->label(__('tbtop-cms::settings.seo.fields.default_description'))
                ->translatable()
                ->helperText(__('tbtop-cms::settings.seo.helpers.default_description'))
                ->rules('nullable|max:500'),
            $s->media('seo_default_og_image')->label(__('tbtop-cms::settings.seo.fields.default_og_image'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.default_og_image'))
                ->variant('preview'),
            $s->select('seo_twitter_card')->label(__('tbtop-cms::settings.seo.fields.twitter_card'))
                ->options([
                    ['value' => 'auto', 'label' => __('tbtop-cms::settings.seo.twitter_card.auto')],
                    ['value' => 'summary', 'label' => __('tbtop-cms::settings.seo.twitter_card.summary')],
                    ['value' => 'summary_large_image', 'label' => __('tbtop-cms::settings.seo.twitter_card.summary_large_image')],
                ])
                ->helperText(__('tbtop-cms::settings.seo.helpers.twitter_card'))
                ->rules('nullable|in:auto,summary,summary_large_image'),
            $s->boolean('seo_indexing_enabled')->label(__('tbtop-cms::settings.seo.fields.indexing_enabled'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.indexing_enabled')),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.seo.sections.gtm'))->variant('subheading'),
            $s->text('seo_gtm_container_id')->label(__('tbtop-cms::settings.seo.fields.gtm_container_id'))
                ->placeholder('GTM-XXXXXXX')
                ->helperText(__('tbtop-cms::settings.seo.helpers.gtm_container_id'))
                ->rules('nullable|max:2000'),
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.seo.sections.identity'))->variant('subheading'),
            $s->select('seo_identity_type')->label(__('tbtop-cms::settings.seo.fields.identity_type'))
                ->options([
                    ['value' => 'none', 'label' => __('tbtop-cms::settings.seo.identity_type.none')],
                    ['value' => 'organization', 'label' => __('tbtop-cms::settings.seo.identity_type.organization')],
                    ['value' => 'person', 'label' => __('tbtop-cms::settings.seo.identity_type.person')],
                ])
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_type'))
                ->rules('nullable|in:none,organization,person'),
            $s->text('seo_identity_name')->label(__('tbtop-cms::settings.seo.fields.identity_name'))
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.identity_name'))->rules('nullable|max:255'),
            $s->media('seo_identity_logo')->label(__('tbtop-cms::settings.seo.fields.identity_logo'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.identity_logo'))
                ->variant('preview'),
            $s->tags('seo_identity_same_as')->label(__('tbtop-cms::settings.seo.fields.identity_same_as'))
                ->rules('nullable|array')->helperText(__('tbtop-cms::settings.seo.helpers.identity_same_as')),
        ];
    }

    /**
     * The GTM container id is normalized on read too (SeoResolver::gtmId()
     * extracts and validates), but normalizing here as well means a re-opened
     * settings page shows the bare "GTM-…" id instead of the full install
     * snippet the admin pasted.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    #[\Override]
    protected function normalizeForm(array $form): array
    {
        if (isset($form['seo_gtm_container_id']) && is_string($form['seo_gtm_container_id'])) {
            $form['seo_gtm_container_id'] = GtmContainerId::extract($form['seo_gtm_container_id']);
        }

        return $form;
    }
}
