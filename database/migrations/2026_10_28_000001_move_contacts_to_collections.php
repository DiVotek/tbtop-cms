<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Tbtop\Cms\Settings\Settings;

/**
 * The built-in Phones, Emails and Socials collections take over Site
 * settings → Contacts: each row of `site.contacts.*` becomes one published
 * entry, in the same order, then the settings key goes.
 *
 * Plain Settings reads and query-builder inserts (no model, no registry), so
 * a later change to the collections cannot break this step. A collection
 * that already has entries is left alone, its settings key included (it was
 * not copied, so it stays recoverable); a rerun finds no keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        $settings = app(Settings::class);

        self::move($settings, 'phones', 'site.contacts.phones', static fn (array $row): ?array => self::only($row, 'number'));
        self::move($settings, 'emails', 'site.contacts.emails', static fn (array $row): ?array => self::only($row, 'address'));
        self::move($settings, 'socials', 'site.contacts.socials', self::social(...));
    }

    /** The settings keys are gone for good: the collections are the source now. */
    public function down(): void {}

    /**
     * One transaction per collection: a failed copy leaves it empty and its
     * key in place, so a rerun copies it again. Settings::forget() bumps the
     * content version, so the site sees the new entries at once.
     *
     * @param  Closure(array<mixed>): (array{0: array<string, string>, 1: string}|null)  $entry  a row's data and title, null to skip it
     */
    private static function move(Settings $settings, string $collection, string $key, Closure $entry): void
    {
        DB::transaction(static function () use ($settings, $collection, $key, $entry): void {
            if (DB::table('tbtop_entries')->where('collection', $collection)->exists()) {
                return;
            }
            $sort = 0;
            foreach (self::rows($settings->get($key)) as $row) {
                $found = $entry($row);
                if ($found !== null) {
                    self::insert($collection, $sort++, ...$found);
                }
            }
            $settings->forget($key);
        });
    }

    /**
     * @param  array<mixed>  $row
     * @return array{0: array<string, string>, 1: string}|null
     */
    private static function only(array $row, string $field): ?array
    {
        $value = self::text($row[$field] ?? null);

        return $value === null ? null : [[$field => $value], $value];
    }

    /**
     * @param  array<mixed>  $row
     * @return array{0: array<string, string>, 1: string}|null
     */
    private static function social(array $row): ?array
    {
        $label = self::text($row['label'] ?? null);
        $href = self::text($row['href'] ?? null);
        if ($label === null || $href === null) {
            return null;
        }
        $name = self::text($row['name'] ?? null);

        return [['label' => $label, ...($name === null ? [] : ['name' => $name]), 'href' => $href], $name ?? $label];
    }

    /** @return list<array<mixed>> */
    private static function rows(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, is_array(...))) : [];
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** @param  array<string, string>  $data */
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
