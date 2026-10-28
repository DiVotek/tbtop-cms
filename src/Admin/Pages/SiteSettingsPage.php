<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\HtmlBlock;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Admin\Collections\CollectionTable;
use Tbtop\Cms\Collection\BuiltIn\Offices;
use Tbtop\Cms\Collection\BuiltIn\Socials;
use Tbtop\Cms\Collection\CollectionRegistry;

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
            'icon' => 'settings',
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
            $s->tabs([
                ['label' => __('tbtop-cms::settings.site.tabs.branding'), 'children' => $this->brandingFields($s)],
                ['label' => __('tbtop-cms::settings.site.tabs.contacts'), 'children' => $this->contactsFields($s)],
            ]),
        ];
    }

    /** @return list<Field|Node> */
    protected function brandingFields(S $s): array
    {
        return [
            $s->text('site_name')->label(__('tbtop-cms::settings.site.fields.site_name'))->required()->rules('max:255'),
            ...$this->brandTextFields($s),
            $s->grid(['cols' => 2], [
                $s->media('site_logo')->label(__('tbtop-cms::settings.site.fields.logo'))->variant('preview'),
                $s->media('site_favicon')->label(__('tbtop-cms::settings.site.fields.favicon'))->variant('preview'),
            ]),
        ];
    }

    /**
     * Host hook: extra brand fields (a logo monogram, a tagline) rendered
     * between the site name and the logo images. Register their settings
     * keys in map().
     *
     * @return list<Field|Node>
     */
    protected function brandTextFields(S $s): array
    {
        return [];
    }

    /**
     * Phones, emails, addresses and profile links are entries of the
     * built-in Offices and Socials collections now; the tab points there.
     *
     * @return list<Field|HtmlBlock|Node|TextBlock>
     */
    protected function contactsFields(S $s): array
    {
        return [
            $s->displayText(__('tbtop-cms::settings.site.helpers.contacts'))->variant('muted'),
            $s->flex([
                $s->action('openOffices')->label(app(CollectionRegistry::class)->get(Offices::KEY)->label())
                    ->icon('map-pin')->url(CollectionTable::listUrl(Offices::KEY)),
                $s->action('openSocials')->label(app(CollectionRegistry::class)->get(Socials::KEY)->label())
                    ->icon('share-2')->url(CollectionTable::listUrl(Socials::KEY)),
            ], gap: 2),
        ];
    }
}
