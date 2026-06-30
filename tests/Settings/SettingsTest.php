<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tbtop\Cms\Settings\Models\Setting;
use Tbtop\Cms\Settings\Settings;
use Tbtop\Cms\Settings\Support\SettingCodec;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['cache.default' => 'array']);
});

it('round-trips scalar and array values', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'Acme');
    $settings->set('seo.meta', ['a' => 1, 'b' => [2, 3]]);

    expect($settings->get('site.name'))->toBe('Acme')
        ->and($settings->get('seo.meta'))->toBe(['a' => 1, 'b' => [2, 3]]);
});

it('returns the default when a key is missing', function (): void {
    $settings = new Settings;

    expect($settings->get('missing.key', 'fallback'))->toBe('fallback')
        ->and($settings->get('missing.key'))->toBeNull();
});

it('invalidates the cache on save', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'A');
    $settings->get('site.name');

    Setting::query()->where('key', 'site.name')->update(['value' => SettingCodec::encode('B', false)]);

    expect((new Settings)->get('site.name'))->toBe('A');

    $settings->set('seo.other', 'x');

    expect((new Settings)->get('site.name'))->toBe('B');
});

it('excludes secret keys from the frontend payload', function (): void {
    $settings = new Settings;
    $settings->set('site.name', 'My Site');
    $settings->set('notifications.mail.smtp_password', 'hunter2');

    $public = $settings->forFrontend();

    expect($public)->toHaveKey('site.name')
        ->and($public['site.name'])->toBe('My Site')
        ->and($public)->not->toHaveKey('notifications.mail.smtp_password');
});

it('stores secret values encrypted at rest', function (): void {
    $settings = new Settings;
    $settings->set('notifications.mail.smtp_password', 'hunter2');

    $raw = Setting::query()->where('key', 'notifications.mail.smtp_password')->value('value');

    expect($raw)->not->toContain('hunter2')
        ->and($settings->get('notifications.mail.smtp_password'))->toBe('hunter2');
});
