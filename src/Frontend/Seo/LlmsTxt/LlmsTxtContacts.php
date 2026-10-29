<?php

declare(strict_types=1);

namespace Tbtop\Cms\Frontend\Seo\LlmsTxt;

use Tbtop\Cms\Frontend\Seo\SiteIdentity;
use Tbtop\Cms\Settings\SiteContacts;

/**
 * The site's contact details as a markdown list: phones, emails and social
 * links from the built-in collections, address and opening hours from the
 * SEO identity. Read from the same sources the JSON-LD identity node uses,
 * so an agent reading llms.txt and one reading the page markup agree.
 */
final readonly class LlmsTxtContacts
{
    public function __construct(
        private SiteContacts $contacts,
        private SiteIdentity $identity,
    ) {}

    /** @return list<string> One `- Label: value` line per fact; empty when nothing is set. */
    public function lines(string $locale, string $defaultLocale): array
    {
        $properties = $this->identity->properties($locale, $defaultLocale);
        $lines = [];

        $this->add($lines, 'phone', implode(', ', $this->contacts->phones($locale)), $locale);
        $this->add($lines, 'email', implode(', ', $this->contacts->emails($locale)), $locale);
        $this->add($lines, 'address', self::address($properties['address'] ?? null), $locale);
        $this->add($lines, 'hours', self::hours($properties['openingHoursSpecification'] ?? null, $locale), $locale);

        foreach ($this->contacts->socials($locale) as $social) {
            $lines[] = '- ' . LlmsTxtMarkdown::inline($social['label']) . ': ' . LlmsTxtMarkdown::url($social['href']);
        }

        return $lines;
    }

    /** @param  list<string>  $lines */
    private function add(array &$lines, string $label, string $value, string $locale): void
    {
        if ($value !== '') {
            $lines[] = '- ' . __('tbtop-cms::llms.contacts.' . $label, [], $locale) . ': ' . LlmsTxtMarkdown::inline($value);
        }
    }

    private static function address(mixed $address): string
    {
        if (! is_array($address)) {
            return '';
        }

        $parts = array_map(
            static fn (string $field): string => is_string($address[$field] ?? null) ? $address[$field] : '',
            ['streetAddress', 'addressLocality', 'addressRegion', 'postalCode', 'addressCountry'],
        );

        return implode(', ', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** `Monday, Tuesday 09:00–18:00; Saturday 10:00–14:00` from the identity's OpeningHoursSpecification rows. */
    private static function hours(mixed $specs, string $locale): string
    {
        if (! is_array($specs)) {
            return '';
        }

        $rows = [];

        foreach ($specs as $spec) {
            $days = array_map(
                static fn (string $url): string => __('tbtop-cms::settings.seo.days.' . strtolower(basename($url)), [], $locale),
                $spec['dayOfWeek'],
            );
            $rows[] = implode(', ', $days) . ' ' . $spec['opens'] . '–' . $spec['closes'];
        }

        return implode('; ', $rows);
    }
}
