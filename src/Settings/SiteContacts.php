<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings;

use Tbtop\Cms\Collection\BuiltIn\Emails;
use Tbtop\Cms\Collection\BuiltIn\Phones;
use Tbtop\Cms\Collection\BuiltIn\Socials;
use Tbtop\Cms\Collection\CollectionEntries;

/**
 * The site's contact details for blocks (header, footer, contact sections)
 * and the SEO identity node: every published entry of the built-in Phones,
 * Emails and Socials collections, in the editor's order. Each read is the
 * cached collection.
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

    /** @return list<string> In the order the editor arranged them. */
    public function phones(?string $locale = null): array
    {
        return $this->column(Phones::KEY, 'number', $locale);
    }

    /** @return list<string> */
    public function emails(?string $locale = null): array
    {
        return $this->column(Emails::KEY, 'address', $locale);
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

    /** @return list<string> One field of a collection's entries, blanks skipped. */
    private function column(string $collection, string $field, ?string $locale): array
    {
        $values = [];
        foreach ($this->entries->of($collection, $locale) as $entry) {
            $value = self::str($entry[$field] ?? null);
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
