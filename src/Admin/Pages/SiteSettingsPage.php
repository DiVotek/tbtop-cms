<?php

declare(strict_types=1);

namespace Tbtop\Cms\Admin\Pages;

use Tbtop\Admin\Dsl\Fields\Field;
use Tbtop\Admin\Dsl\HtmlBlock;
use Tbtop\Admin\Dsl\Node;
use Tbtop\Admin\Dsl\S;
use Tbtop\Admin\Dsl\TextBlock;
use Tbtop\Cms\Settings\SiteContacts;

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
            'phones' => SiteContacts::PHONES,
            'emails' => SiteContacts::EMAILS,
            'socials' => SiteContacts::SOCIALS,
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

    /** @return list<Field|HtmlBlock|Node|TextBlock> */
    protected function contactsFields(S $s): array
    {
        return [
            $s->displayText(__('tbtop-cms::settings.site.helpers.contacts'))->variant('muted'),
            $s->displayHtml(self::twoColumnRows('phones', 'emails', 'socials')),
            $s->repeater('phones')->label(__('tbtop-cms::settings.site.fields.phones'))->fields([
                $s->text('number')->label(__('tbtop-cms::settings.site.fields.phone_number'))->placeholder('+380 44 000 00 00')->required()->rules('max:40'),
            ]),
            $s->repeater('emails')->label(__('tbtop-cms::settings.site.fields.emails'))->fields([
                $s->text('address')->label(__('tbtop-cms::settings.site.fields.email_address'))->required()->rules('email|max:190'),
            ]),
            $s->repeater('socials')->label(__('tbtop-cms::settings.site.fields.socials'))->fields([
                $s->text('label')->label(__('tbtop-cms::settings.site.fields.social_label'))->placeholder('TG')->required()->rules('max:8'),
                $s->text('name')->label(__('tbtop-cms::settings.site.fields.social_name'))->placeholder('Telegram')->rules('nullable|max:40'),
                $s->text('href')->label(__('tbtop-cms::settings.site.fields.social_href'))->required()->rules('url|max:255'),
            ]),
        ];
    }

    /**
     * Lays the rows of the named repeaters out two per line on wide screens:
     * a contact is one short input, a full-width row per contact wastes most
     * of the page. The repeater itself has no column option, so this styles
     * its root (`[data-field]`, a flex column) from the outside; the "add"
     * button stays on its own line. If the markup changes, rows fall back
     * to the stacked layout — nothing breaks.
     */
    private static function twoColumnRows(string ...$fields): string
    {
        $roots = implode(', ', array_map(static fn (string $f): string => sprintf('[data-field="%s"]', $f), $fields));
        $buttons = implode(', ', array_map(static fn (string $f): string => sprintf('[data-field="%s"] > button', $f), $fields));

        return "<style>@media (min-width: 768px) { {$roots} { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); } {$buttons} { grid-column: 1 / -1; justify-self: start; } }</style>";
    }

    /**
     * Stores trimmed rows without the blank ones, so readers compare clean
     * values and an untouched empty row does not count as a contact.
     *
     * @param  array<string, mixed>  $form
     * @return array<string, mixed>
     */
    #[\Override]
    protected function normalizeForm(array $form): array
    {
        foreach (['phones' => ['number'], 'emails' => ['address'], 'socials' => ['label', 'name', 'href']] as $field => $keys) {
            if (array_key_exists($field, $form)) {
                $form[$field] = self::cleanRows($form[$field], $keys);
            }
        }

        return $form;
    }

    /**
     * @param  list<string>  $keys
     * @return list<array<string, string>>
     */
    private static function cleanRows(mixed $rows, array $keys): array
    {
        $clean = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $values = [];
            foreach ($keys as $key) {
                $value = $row[$key] ?? null;
                if (is_string($value) && trim($value) !== '') {
                    $values[$key] = trim($value);
                }
            }
            if ($values !== []) {
                $clean[] = $values;
            }
        }

        return $clean;
    }
}
