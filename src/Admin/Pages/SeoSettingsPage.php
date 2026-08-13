<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Illuminate\Validation\ValidationException;
use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Frontend\Seo\GtmContainerId;
use Tbtop\Cms\Frontend\Seo\ProfileUrl;
use Tbtop\Cms\Frontend\Seo\RobotsTxt;

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
            'seo_robots_extra' => 'seo.robots_extra',
            'seo_sitemap_per_chunk' => 'seo.sitemap_per_chunk',
            'seo_gtm_container_id' => 'seo.gtm_container_id',
            'seo_identity_type' => 'seo.identity.type',
            'seo_identity_name' => 'seo.identity.name',
            'seo_identity_logo' => 'seo.identity.logo',
            'seo_identity_same_as' => 'seo.identity.same_as',
            'seo_twitter_card' => 'seo.twitter_card',
            'seo_not_found_enabled' => 'seo.not_found.enabled',
            'seo_not_found_retention_days' => 'seo.not_found.retention_days',
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
            // Capped at the spec's own 50 000 ceiling; the useful value is far
            // below it — see the helper text and SitemapEntryBuilder.
            $s->number('seo_sitemap_per_chunk')->label(__('tbtop-cms::settings.seo.fields.sitemap_per_chunk'))
                ->placeholder((string) config('tbtop-cms.seo.sitemap.per_chunk', 1000))
                ->helperText(__('tbtop-cms::settings.seo.helpers.sitemap_per_chunk'))
                ->rules('nullable|integer|min:1|max:50000'),
            // The owned-directive check lives in normalizeForm(), not here —
            // see guardRobotsExtra().
            $s->textarea('seo_robots_extra')->label(__('tbtop-cms::settings.seo.fields.robots_extra'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.robots_extra'))
                ->placeholder(__('tbtop-cms::settings.seo.placeholders.robots_extra'))
                ->rules(['nullable', 'string', 'max:4000']),
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
            $s->displayDivider(),
            $s->displayText(__('tbtop-cms::settings.seo.sections.not_found'))->variant('subheading'),
            $s->boolean('seo_not_found_enabled')->label(__('tbtop-cms::settings.seo.fields.not_found_enabled'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.not_found_enabled')),
            $s->number('seo_not_found_retention_days')->label(__('tbtop-cms::settings.seo.fields.not_found_retention_days'))
                ->helperText(__('tbtop-cms::settings.seo.helpers.not_found_retention_days'))
                ->rules('nullable|integer|min:1'),
        ];
    }

    /**
     * Rejects lines that would fight the directives the CMS writes itself.
     *
     * Thrown from the save path rather than declared as a field rule: the DSL
     * collects rules as strings (CollectsRules::appendRules runs array_unique
     * over them), so a closure rule cannot be expressed there, and the check
     * reads the same OWNED_DIRECTIVES list the renderer uses — a regex spelled
     * out here would be a second copy free to drift.
     *
     * Rejecting rather than stripping: RobotsTxt drops these lines when it
     * renders either way, and an editor who wrote one deserves to be told why
     * it will not appear instead of finding it missing later.
     *
     * @param  array<string, mixed>  $form
     */
    private static function guardRobotsExtra(array $form): void
    {
        $value = $form['seo_robots_extra'] ?? null;

        if (! is_string($value)) {
            return;
        }

        foreach (preg_split('/\R/', $value) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            if (RobotsTxt::claimsOwnedDirective($line)) {
                throw ValidationException::withMessages([
                    'seo_robots_extra' => __('tbtop-cms::settings.seo.validation.robots_owned', ['line' => trim($line)]),
                ]);
            }
        }
    }

    /**
     * Both cleanups also happen on read (GtmContainerId::fromSettings()
     * extracts and validates, sameAs() filters), but normalizing here as well means a
     * re-opened settings page shows the cleaned value — the bare "GTM-…" id
     * instead of the pasted install snippet, and only the profile links that
     * will actually be published (the tags field can't express a per-element
     * url rule, so non-URL entries are dropped rather than rejected).
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    #[\Override]
    protected function normalizeForm(array $form): array
    {
        self::guardRobotsExtra($form);

        if (isset($form['seo_gtm_container_id']) && is_string($form['seo_gtm_container_id'])) {
            $form['seo_gtm_container_id'] = GtmContainerId::extract($form['seo_gtm_container_id']);
        }

        if (isset($form['seo_identity_same_as']) && is_array($form['seo_identity_same_as'])) {
            $form['seo_identity_same_as'] = array_values(array_filter(
                $form['seo_identity_same_as'],
                static fn (mixed $url): bool => is_string($url) && ProfileUrl::valid($url),
            ));
        }

        return $form;
    }
}
