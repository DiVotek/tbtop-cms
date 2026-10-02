<?php

declare(strict_types=1);

namespace Tbtop\Cms\Settings;

/**
 * The site's contact details from Site settings, for blocks (header, footer,
 * contact sections) and the SEO identity node. Blank rows are already
 * dropped on save; this guards against hand-written settings too.
 */
final class SiteContacts
{
    public const string PHONES = 'site.contacts.phones';

    public const string EMAILS = 'site.contacts.emails';

    public const string SOCIALS = 'site.contacts.socials';

    public function __construct(private readonly Settings $settings) {}

    /** @return list<string> In the order the editor arranged them. */
    public function phones(): array
    {
        return $this->column(self::PHONES, 'number');
    }

    /** @return list<string> */
    public function emails(): array
    {
        return $this->column(self::EMAILS, 'address');
    }

    /** @return list<array{label: string, name: string|null, href: string}> Rows missing a label or a link are skipped. */
    public function socials(): array
    {
        $socials = [];
        foreach ($this->rows(self::SOCIALS) as $row) {
            $label = self::str($row['label'] ?? null);
            $href = self::str($row['href'] ?? null);
            if ($label !== null && $href !== null) {
                $socials[] = ['label' => $label, 'name' => self::str($row['name'] ?? null), 'href' => $href];
            }
        }

        return $socials;
    }

    /** @return list<string> */
    private function column(string $key, string $field): array
    {
        $values = [];
        foreach ($this->rows($key) as $row) {
            $value = self::str($row[$field] ?? null);
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $key): array
    {
        $value = $this->settings->get($key);

        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
