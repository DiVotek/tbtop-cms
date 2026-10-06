<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Settings\Settings;

uses(RefreshDatabase::class);

function identityContactsMigration(): Migration
{
    return require dirname(__DIR__, 2) . '/database/migrations/2026_10_07_000001_move_identity_contacts_to_site_contacts.php';
}

it('moves legacy identity telephone, email and profile links into empty site contacts and deletes the identity keys', function (): void {
    $settings = app(Settings::class);
    $settings->set('seo.identity.telephone', ' +380 44 123 4567 ');
    $settings->set('seo.identity.email', 'press@example.com');
    $settings->set('seo.identity.same_as', ['https://www.facebook.com/acme', 'not a url', 'https://t.me/acme']);

    $migration = identityContactsMigration();
    $migration->up();
    $migration->up();

    expect($settings->get('site.contacts.phones'))->toBe([['number' => '+380 44 123 4567']])
        ->and($settings->get('site.contacts.emails'))->toBe([['address' => 'press@example.com']])
        ->and($settings->get('site.contacts.socials'))->toBe([
            ['label' => 'facebook', 'name' => 'facebook.com', 'href' => 'https://www.facebook.com/acme'],
            ['label' => 't', 'name' => 't.me', 'href' => 'https://t.me/acme'],
        ])
        ->and($settings->get('seo.identity.telephone'))->toBeNull()
        ->and($settings->get('seo.identity.email'))->toBeNull()
        ->and($settings->get('seo.identity.same_as'))->toBeNull();
});

it('keeps contacts the editor already set and still deletes the identity keys', function (): void {
    $settings = app(Settings::class);
    $settings->set('site.contacts.phones', [['number' => '+380 44 555 0001']]);
    $settings->set('site.contacts.emails', [['address' => 'hello@example.com']]);
    $settings->set('site.contacts.socials', [['label' => 'FB', 'href' => 'https://facebook.com/acme']]);
    $settings->set('seo.identity.telephone', '+380 44 123 4567');
    $settings->set('seo.identity.email', 'press@example.com');
    $settings->set('seo.identity.same_as', ['https://www.wikidata.org/wiki/Q1']);

    identityContactsMigration()->up();

    expect($settings->get('site.contacts.phones'))->toBe([['number' => '+380 44 555 0001']])
        ->and($settings->get('site.contacts.emails'))->toBe([['address' => 'hello@example.com']])
        ->and($settings->get('site.contacts.socials'))->toBe([['label' => 'FB', 'href' => 'https://facebook.com/acme']])
        ->and($settings->get('seo.identity.telephone'))->toBeNull()
        ->and($settings->get('seo.identity.email'))->toBeNull()
        ->and($settings->get('seo.identity.same_as'))->toBeNull();
});
