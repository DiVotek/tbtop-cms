<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Language\Languages;
use Tbtop\Cms\Settings\Settings;

/**
 * The built-in Offices and Socials collections take over from settings:
 * Site settings → Contacts (phones, emails, socials) and the SEO identity's
 * address, opening hours and coordinates. The values become one published
 * office and one published entry per social row, then the settings keys go.
 *
 * Plain Settings reads and query-builder inserts (no model, no registry), so
 * a later change to the collections cannot break this step. A collection
 * that already has entries is left alone, and a rerun finds no keys.
 */
return new class extends Migration
{
    private const array OFFICE_KEYS = [
        'site.contacts.phones',
        'site.contacts.emails',
        'seo.identity.address.street',
        'seo.identity.address.locality',
        'seo.identity.address.region',
        'seo.identity.address.postal_code',
        'seo.identity.address.country',
        'seo.identity.geo.latitude',
        'seo.identity.geo.longitude',
        'seo.identity.opening_hours',
    ];

    public function up(): void
    {
        $settings = app(Settings::class);

        if (! self::hasEntries('offices')) {
            $office = self::office($settings);
            if ($office !== null) {
                self::insert('offices', 0, $office, self::text($settings->get('site.name')) ?? '');
            }
        }

        if (! self::hasEntries('socials')) {
            foreach (self::socials($settings->get('site.contacts.socials')) as $i => $row) {
                self::insert('socials', $i, $row, $row['name'] ?? $row['label']);
            }
        }

        foreach ([...self::OFFICE_KEYS, 'site.contacts.socials'] as $key) {
            $settings->forget($key);
        }
    }

    /** The settings keys are gone for good: the collections are the source now. */
    public function down(): void {}

    /** @return array<string, mixed>|null null when settings hold nothing an office would show */
    private static function office(Settings $settings): ?array
    {
        $data = array_filter([
            'street' => self::localized($settings->get('seo.identity.address.street')),
            'locality' => self::localized($settings->get('seo.identity.address.locality')),
            'region' => self::localized($settings->get('seo.identity.address.region')),
            'postal_code' => self::text($settings->get('seo.identity.address.postal_code')),
            'country' => self::text($settings->get('seo.identity.address.country')),
            'latitude' => self::number($settings->get('seo.identity.geo.latitude')),
            'longitude' => self::number($settings->get('seo.identity.geo.longitude')),
            'phones' => self::rows($settings->get('site.contacts.phones'), 'number'),
            'emails' => self::rows($settings->get('site.contacts.emails'), 'address'),
            'opening_hours' => self::hours($settings->get('seo.identity.opening_hours')),
        ], static fn (mixed $value): bool => $value !== null && $value !== []);

        if ($data === []) {
            return null;
        }

        $name = self::text($settings->get('site.name')) ?? 'Office';

        return ['name' => [app(Languages::class)->defaultLocale() => $name], ...$data];
    }

    /** @return list<array{label: string, name?: string, href: string}> */
    private static function socials(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            $label = self::text($row['label'] ?? null);
            $href = self::text($row['href'] ?? null);
            if ($label !== null && $href !== null) {
                $name = self::text($row['name'] ?? null);
                $out[] = ['label' => $label, ...($name === null ? [] : ['name' => $name]), 'href' => $href];
            }
        }

        return $out;
    }

    /** @return list<array<string, string>> */
    private static function rows(mixed $rows, string $key): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $value = is_array($row) ? self::text($row[$key] ?? null) : null;
            if ($value !== null) {
                $out[] = [$key => $value];
            }
        }

        return $out;
    }

    /** @return list<array{days: list<string>, opens: string, closes: string}> */
    private static function hours(mixed $rows): array
    {
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_array($row['days'] ?? null) && is_string($row['opens'] ?? null) && is_string($row['closes'] ?? null)) {
                $out[] = ['days' => array_values(array_filter($row['days'], is_string(...))), 'opens' => $row['opens'], 'closes' => $row['closes']];
            }
        }

        return $out;
    }

    /** A per-locale map stays one; a plain string (written by a script) is kept as is. @return array<string, string>|string|null */
    /** @return array<string, string>|string|null */
    private static function localized(mixed $value): array | string | null
    {
        if (is_array($value)) {
            $map = array_filter($value, static fn (mixed $v): bool => is_string($v) && trim($v) !== '');

            return $map === [] ? null : $map;
        }

        return self::text($value);
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function number(mixed $value): int | float | null
    {
        return is_numeric($value) ? $value + 0 : null;
    }

    private static function hasEntries(string $collection): bool
    {
        return DB::table('tbtop_entries')->where('collection', $collection)->exists();
    }

    /** @param  array<string, mixed>  $data */
    private static function insert(string $collection, int $sort, array $data, string $title): void
    {
        DB::table('tbtop_entries')->insert([
            'collection' => $collection,
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'title' => mb_substr($title, 0, 255),
            'status' => 'published',
            'sort_order' => $sort,
            'lock_version' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};
