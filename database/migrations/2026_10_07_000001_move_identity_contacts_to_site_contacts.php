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
 * on SQLite and MySQL. A rerun finds no identity keys and does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(Settings::class);
        $contacts = new SiteContacts($settings);

        $telephone = self::text($settings->get('seo.identity.telephone'));
        if ($telephone !== null && $contacts->phones() === []) {
            $settings->set(SiteContacts::PHONES, [...self::rows($settings, SiteContacts::PHONES), ['number' => $telephone]]);
        }

        $email = self::text($settings->get('seo.identity.email'));
        if ($email !== null && $contacts->emails() === []) {
            $settings->set(SiteContacts::EMAILS, [...self::rows($settings, SiteContacts::EMAILS), ['address' => $email]]);
        }

        $profiles = self::profiles($settings->get('seo.identity.same_as'));
        if ($profiles !== [] && $contacts->socials() === []) {
            $settings->set(SiteContacts::SOCIALS, [...self::rows($settings, SiteContacts::SOCIALS), ...$profiles]);
        }

        foreach (['seo.identity.telephone', 'seo.identity.email', 'seo.identity.same_as'] as $key) {
            $settings->forget($key);
        }
    }

    /** The SEO keys are gone for good: their values now live in the contacts, nothing reads them back. */
    public function down(): void {}

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
