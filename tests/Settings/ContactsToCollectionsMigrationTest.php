<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Collection\Models\Entry;
use Tbtop\Cms\Language\Models\Language;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Language::factory()->default()->create(['locale' => 'en']);
});

function contactsToCollectionsMigration(): Migration
{
    return require dirname(__DIR__, 2) . '/database/migrations/2026_10_28_000001_move_contacts_to_collections.php';
}

/** @return list<array<string, mixed>> */
function migratedEntries(string $collection): array
{
    return Entry::query()->where('collection', $collection)->orderBy('sort_order')->get()
        ->map(static fn (Entry $entry): array => ['title' => $entry->title, 'status' => $entry->status, 'data' => $entry->data])->all();
}

it('moves each contacts row into a published entry, in order, and drops the settings keys', function (): void {
    $settings = app(Settings::class);
    $settings->set('site.contacts.phones', [['number' => '+380 44 000 0001'], ['number' => ' '], ['number' => '+380 44 000 0002']]);
    $settings->set('site.contacts.emails', [['address' => 'hi@example.com']]);
    $settings->set('site.contacts.socials', [['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme'], ['label' => 'X', 'href' => ''], ['label' => 'FB', 'href' => 'https://facebook.com/acme']]);
    $settings->set('seo.identity.address.street', ['en' => '1 Main St']);

    $migration = contactsToCollectionsMigration();
    $migration->up();
    $migration->up();

    expect(migratedEntries('phones'))->toBe([
        ['title' => '+380 44 000 0001', 'status' => Entry::PUBLISHED, 'data' => ['number' => '+380 44 000 0001']],
        ['title' => '+380 44 000 0002', 'status' => Entry::PUBLISHED, 'data' => ['number' => '+380 44 000 0002']],
    ])
        ->and(migratedEntries('emails'))->toBe([['title' => 'hi@example.com', 'status' => Entry::PUBLISHED, 'data' => ['address' => 'hi@example.com']]])
        // MySQL's json column stores object keys in its own order.
        ->and(array_column(migratedEntries('socials'), 'data'))->toEqual([
            ['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme'],
            ['label' => 'FB', 'href' => 'https://facebook.com/acme'],
        ])
        ->and(array_column(migratedEntries('socials'), 'title'))->toBe(['Telegram', 'FB']);

    foreach (['site.contacts.phones', 'site.contacts.emails', 'site.contacts.socials'] as $key) {
        expect($settings->get($key))->toBeNull();
    }
    // The identity's address stays in its own settings.
    expect($settings->get('seo.identity.address.street'))->toBe(['en' => '1 Main St']);
});

it('creates nothing when the settings hold no contacts', function (): void {
    contactsToCollectionsMigration()->up();

    expect(Entry::query()->count())->toBe(0);
});

it('keeps the settings of a collection that already has entries', function (): void {
    $settings = app(Settings::class);
    $settings->set('site.contacts.phones', [['number' => '+380 44 000 0001']]);
    $settings->set('site.contacts.socials', [['label' => 'TG', 'href' => 'https://t.me/acme']]);
    Entry::query()->create(['collection' => 'phones', 'data' => ['number' => '+380 44 999 9999'], 'title' => '+380 44 999 9999']);

    contactsToCollectionsMigration()->up();

    expect(Entry::query()->where('collection', 'phones')->count())->toBe(1)
        ->and($settings->get('site.contacts.phones'))->toBe([['number' => '+380 44 000 0001']])
        ->and(Entry::query()->where('collection', 'socials')->count())->toBe(1)
        ->and($settings->get('site.contacts.socials'))->toBeNull();
});
