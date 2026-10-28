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

it('moves the contacts and the identity address into one published office and the socials into entries', function (): void {
    $settings = app(Settings::class);
    $settings->set('site.name', 'Acme');
    $settings->set('site.contacts.phones', [['number' => '+380 44 000 0001'], ['number' => ' ']]);
    $settings->set('site.contacts.emails', [['address' => 'hi@example.com']]);
    $settings->set('site.contacts.socials', [['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme'], ['label' => 'X', 'href' => '']]);
    $settings->set('seo.identity.address.street', ['en' => '1 Main St', 'uk' => 'вул. Головна, 1']);
    $settings->set('seo.identity.address.locality', ['en' => 'Kyiv']);
    $settings->set('seo.identity.address.country', 'UA');
    $settings->set('seo.identity.geo.latitude', '50.45');
    $settings->set('seo.identity.geo.longitude', 30.52);
    $settings->set('seo.identity.opening_hours', [['days' => ['Monday'], 'opens' => '09:00', 'closes' => '18:00']]);

    $migration = contactsToCollectionsMigration();
    $migration->up();
    $migration->up();

    $office = Entry::query()->where('collection', 'offices')->sole();
    expect($office->status)->toBe(Entry::PUBLISHED)
        ->and($office->title)->toBe('Acme')
        ->and($office->data)->toEqual([
            'name' => ['en' => 'Acme'],
            'street' => ['en' => '1 Main St', 'uk' => 'вул. Головна, 1'],
            'locality' => ['en' => 'Kyiv'],
            'country' => 'UA',
            'latitude' => 50.45,
            'longitude' => 30.52,
            'phones' => [['number' => '+380 44 000 0001']],
            'emails' => [['address' => 'hi@example.com']],
            'opening_hours' => [['days' => ['Monday'], 'opens' => '09:00', 'closes' => '18:00']],
        ]);

    $socials = Entry::query()->where('collection', 'socials')->get();
    expect($socials)->toHaveCount(1)
        ->and($socials[0]->data)->toEqual(['label' => 'TG', 'name' => 'Telegram', 'href' => 'https://t.me/acme']);

    foreach (['site.contacts.phones', 'site.contacts.socials', 'seo.identity.address.street', 'seo.identity.opening_hours', 'seo.identity.geo.latitude'] as $key) {
        expect($settings->get($key))->toBeNull();
    }
});

it('creates no office when the settings hold nothing for one', function (): void {
    app(Settings::class)->set('site.name', 'Acme');

    contactsToCollectionsMigration()->up();

    expect(Entry::query()->count())->toBe(0);
});
