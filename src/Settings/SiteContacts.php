<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings;

use Tbtop\Cms\Collection\BuiltIn\Offices;
use Tbtop\Cms\Collection\BuiltIn\Socials;
use Tbtop\Cms\Collection\CollectionEntries;

/**
 * The site's contact details for blocks (header, footer, contact sections)
 * and the SEO identity node, from the built-in collections: phones and
 * emails are the main office's (the first published Offices entry), the
 * socials every published Socials entry. Each read is the cached collection.
 */
final class SiteContacts
{
    /**
     * The settings keys these lists lived under until the collections took
     * over (2026_10_28_000001). Kept as the names of the matching
     * `cms.settings` props, which ShareCmsProps still fills for templates.
     */
    public const string PHONES = 'site.contacts.phones';

    public const string EMAILS = 'site.contacts.emails';

    public const string SOCIALS = 'site.contacts.socials';

    public function __construct(private readonly CollectionEntries $entries) {}

    /** @return list<string> The main office's, in the order the editor arranged them. */
    public function phones(?string $locale = null): array
    {
        return self::column($this->offices($locale)[0]['phones'] ?? null, 'number');
    }

    /** @return list<string> The main office's. */
    public function emails(?string $locale = null): array
    {
        return self::column($this->offices($locale)[0]['emails'] ?? null, 'address');
    }

    /** @return list<array{label: string, name: string|null, href: string}> Entries missing a label or a link are skipped. */
    public function socials(?string $locale = null): array
    {
        $socials = [];
        foreach ($this->entries->of(Socials::KEY, $locale) as $entry) {
            $label = self::str($entry['label'] ?? null);
            $href = self::str($entry['href'] ?? null);
            if ($label !== null && $href !== null) {
                $socials[] = ['label' => $label, 'name' => self::str($entry['name'] ?? null), 'href' => $href];
            }
        }

        return $socials;
    }

    /**
     * The published offices resolved for the locale, the main one first.
     *
     * @return list<array<string, mixed>>
     */
    public function offices(?string $locale = null): array
    {
        return $this->entries->of(Offices::KEY, $locale)->all();
    }

    /**
     * One string column of repeater rows, blanks skipped.
     *
     * @return list<string>
     */
    public static function column(mixed $rows, string $field): array
    {
        $values = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $value = is_array($row) ? self::str($row[$field] ?? null) : null;
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
