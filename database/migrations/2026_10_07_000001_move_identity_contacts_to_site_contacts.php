<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Tbtop\Cms\Frontend\Seo\ProfileUrl;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\SiteContacts;

/**
 * Site settings → Contacts becomes the only source of the identity's
 * telephone, email and profile links. For each, a legacy SEO identity value
 * is copied into the contacts when they hold none, then the SEO key is
 * deleted either way — the contacts win where both are set, which is what
 * the editor sees on the Contacts tab.
 *
 * Plain Settings reads and writes (no raw JSON in SQL), so it runs the same
 * on SQLite and MySQL. Reads the settings rows directly: SiteContacts reads
 * the collections now, whose table a later migration creates. A rerun finds
 * no identity keys and does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(Settings::class);

        $telephone = self::text($settings->get('seo.identity.telephone'));
        if ($telephone !== null && ! self::filled($settings, SiteContacts::PHONES, ['number'])) {
            $settings->set(SiteContacts::PHONES, [...self::rows($settings, SiteContacts::PHONES), ['number' => $telephone]]);
        }

        $email = self::text($settings->get('seo.identity.email'));
        if ($email !== null && ! self::filled($settings, SiteContacts::EMAILS, ['address'])) {
            $settings->set(SiteContacts::EMAILS, [...self::rows($settings, SiteContacts::EMAILS), ['address' => $email]]);
        }

        $profiles = self::profiles($settings->get('seo.identity.same_as'));
        if ($profiles !== [] && ! self::filled($settings, SiteContacts::SOCIALS, ['label', 'href'])) {
            $settings->set(SiteContacts::SOCIALS, [...self::rows($settings, SiteContacts::SOCIALS), ...$profiles]);
        }

        foreach (['seo.identity.telephone', 'seo.identity.email', 'seo.identity.same_as'] as $key) {
            $settings->forget($key);
        }
    }

    /** The SEO keys are gone for good: their values now live in the contacts, nothing reads them back. */
    public function down(): void {}

    /**
     * Whether a contacts list holds a usable row: one with every key filled.
     *
     * @param  list<string>  $keys
     */
    private static function filled(Settings $settings, string $key, array $keys): bool
    {
        return array_any(
            self::rows($settings, $key),
            static fn (mixed $row): bool => is_array($row) && array_all($keys, static fn (string $k): bool => self::text($row[$k] ?? null) !== null),
        );
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Rows already stored but unusable (no label or link) stay; the copy is appended.
     *
     * @return list<mixed>
     */
    private static function rows(Settings $settings, string $key): array
    {
        $value = $settings->get($key);

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * A contacts social row needs a short label (max 8, the Contacts form's
     * rule); a bare URL has none, so it is named after its host:
     * https://www.facebook.com/acme → label "facebook", name "facebook.com".
     *
     * @return list<array{label: string, name: string, href: string}>
     */
    private static function profiles(mixed $value): array
    {
        $rows = [];
        foreach (is_array($value) ? $value : [] as $url) {
            if (! is_string($url) || ! ProfileUrl::valid(trim($url))) {
                continue;
            }
            $url = trim($url);
            $host = (string) preg_replace('/^www\./i', '', (string) parse_url($url, PHP_URL_HOST));
            $rows[] = ['label' => mb_substr(explode('.', $host)[0], 0, 8), 'name' => mb_substr($host, 0, 40), 'href' => $url];
        }

        return $rows;
    }
};
